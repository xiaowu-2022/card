import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import ts from 'typescript';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8'));
const origin = 'http://127.0.0.1:5249', token = '12|' + 'A'.repeat(64);
const channel = 'C'.repeat(64);
const compile = path => ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
 const browser = await engine.launch({ headless: true, ...options });
 try {
  let anonymousSession = false;
  const page = await browser.newPage({viewport:{width:390,height:844}}), requests=[], errors=[];
  page.on('pageerror', e=>errors.push(e.message));
  await page.route('**/*', route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.origin!==origin)return route.abort();
   if(url.pathname.startsWith('/api/')){
    const path=url.pathname.replace('/api/mobile/v1',''),signedIn=req.headers().authorization==='Bearer '+token;
    requests.push({path,signedIn});
    if(path==='/login')return route.fulfill({status:201,json:{token}});
    if(path==='/session/bridge/publish')return route.fulfill({json:{revision:1}});
    if(path==='/bootstrap')return route.fulfill({json:{...fixture.authenticated,user:signedIn?fixture.authenticated.user:null,restricted:false,unread:null,publicAssets:{}}});
    if(path==='/session')return route.fulfill({json:{tenant:fixture.authenticated.tenant,user:signedIn && !anonymousSession?fixture.authenticated.user:null,restricted:false}});
    if(path==='/home/summary')return route.fulfill({json:fixture.pages['/dashboard'].props});
    if(path==='/home/activity')return route.fulfill({json:{items:[],hasMore:false}});
    return route.fulfill({json:{messages:0,support:0}});
   }
   const path='public/h5'+(url.pathname==='/'?'/index.html':url.pathname);
   try{return route.fulfill({body:readFileSync(path),contentType:path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':'text/html'});}catch{return route.abort();}
  });
  await page.goto(origin+'/?app_webview=1&native_auth=token-v1#/pages/login/index');
  await page.evaluate(({tenantId,channel})=>{window.__consumerTokenBridge={tenantId,channel,token:null};window.dispatchEvent(new Event('consumer-token-bridge'));},{tenantId:fixture.authenticated.tenant.id,channel});
  await page.locator('input[name="password"]').waitFor();
  await page.locator('input[name="email"]').fill('offline@example.test');
  await page.locator('input[name="password"]').fill('offline-password');
  await page.locator('.auth-submit').click();
  await page.waitForURL(/#\/pages\/assets\/index/);
  await page.waitForTimeout(1500);
  assert.equal(new URL(page.url()).hash,'#/pages/assets/index');
  assert.equal(await page.locator('input[name="password"]').count(),0);
  assert.equal(await page.evaluate(()=>window.__consumerSessionState.signedIn),true);
  anonymousSession = true;
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', {configurable:true, value:true}); document.dispatchEvent(new Event('visibilitychange')); delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
  await page.waitForTimeout(500);
  assert.ok(requests.some(request => request.path === '/session'), 'the foreground identity check actually ran');
  assert.equal(new URL(page.url()).hash,'#/pages/assets/index', 'an anomalous guest response cannot bounce the authenticated page to login');
  assert.equal(await page.evaluate(()=>window.__consumerSessionState.signedIn),true);
  assert.equal(requests.filter(request => request.path === '/login').length,1);
  assert.deepEqual(errors,[]);
  console.log('PASS '+name+': WebView login to ordinary home, bearer reads, anomalous guest response retains identity and route');
 }finally{await browser.close();}
}
