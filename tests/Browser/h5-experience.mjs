import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture=JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin='http://127.0.0.1:5237',root='dist/clients/specpay/release/h5',out='artifacts/h5-experience';
mkdirSync(out,{recursive:true});
for(const [name,engine,launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser=await engine.launch({headless:true,...launch});
 try {
  const context=await browser.newContext({viewport:{width:375,height:812},isMobile:true,hasTouch:true});
  const page=await context.newPage(), errors=[], writes=[],held=[];
  let mode='ok', signedIn=true;
  const messages=Array.from({length:25},(_,i)=>({id:`11111111-1111-4111-8111-${String(i).padStart(12,'0')}`,kind:'PLATFORM',template:null,parameters:{},title:`Offline message ${i}`,body:'Fixture message, no real recipient.',href:null,time:'2026-10-13T00:00:00Z',readAt:'2026-10-13T00:00:00Z'}));
  page.on('pageerror',e=>{errors.push(e.message);console.log(name,'pageerror',e.message);});
  page.setDefaultTimeout(10000);
  await context.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.origin!==origin)return route.abort();
   if(u.pathname.startsWith('/api/v1')) {
    const path=u.pathname.slice(7);
    if(req.method()!=='GET') {
     writes.push(path);
     if(path==='/login')return route.fulfill({status:422,json:{errors:{email:['The email field must be a valid email address.'],password:['The password field is required.']}}});
     if(path==='/logout'){signedIn=false;return route.fulfill({json:{}});}
     if(path==='/client/wallet/withdrawals')return route.fulfill({status:422,json:{errors:{address:['The address field format is invalid.']}}});
     if(path==='/client/wallet/top-ups')return route.fulfill({status:422,json:{errors:{requested_amount:['The requested amount field format is invalid.']}}});
     if(path==='/client/cards/cardholder')return route.fulfill({status:422,json:{errors:{legal_last_name:['The legal last name field is required.'],email:['The email field must be a valid email address.']}}});
     return route.fulfill({json:{}});
    }
    if(path==='/messages') {
     const result={items:messages,page:Number(u.searchParams.get('page')??1),hasMore:true};
     if(mode==='hold'){held.push({route,result});return;}
     if(mode==='fail')return route.fulfill({status:503,json:{}});
     if(mode==='deny')return route.fulfill({status:403,json:{}});
     return route.fulfill({json:result});
    }
    if(path.startsWith('/messages/'))return route.fulfill({json:messages.find(x=>path.includes(x.id))});
    if(path==='/client/wallet/withdrawals') {
     const dto=structuredClone(fixture.pages['/wallet/withdrawals?fixture=verified']);
     dto.props.history={data:messages.map(m=>({id:m.id,amount:'20',feeAmount:'1',receiveAmount:'19',asset:'USDT',maskedAddress:'TOFFLINE...',requestedAt:m.time,state:'completed'})),currentPage:3,lastPage:4};
     if(mode==='hold'){held.push({route,result:dto});return;}
     return route.fulfill({json:dto});
    }
    if(path.startsWith('/client/wallet/withdrawals/'))return route.fulfill({json:{component:'user/WithdrawalStatus',props:{order:{id:path.split('/').pop(),amount:'20',feeAmount:'1',receiveAmount:'19',asset:'USDT',network:'TRC20',maskedAddress:'TOFFLINE...',status:'SUCCEEDED',txHash:null,reviewReason:null}}}});
    if(path==='/client/account/settings')return route.fulfill({json:fixture.pages['/account/settings']});
    if(path==='/client/wallet/transfer')return route.fulfill({json:fixture.pages['/wallet/transfer?fixture=verified']});
    if(path==='/client/wallet/withdraw')return route.fulfill({json:fixture.pages['/wallet/withdraw?fixture=verified']});
    if(path==='/client/wallet/top-up') {
     const dto=structuredClone(fixture.pages['/wallet/top-up']);Object.assign(dto.props,{available:{amount:'1000',asset:'USDT'},wallet:{id:'offline-wallet',asset:'USDT'},topupAvailable:true,minimum:'1'});
     return route.fulfill({json:dto});
    }
    if(path==='/client/cards') {
     const dto=structuredClone(fixture.pages['/cards?fixture=verified']);dto.props.products[0].readyForSetup=true;
     return route.fulfill({json:dto});
    }
    if(path==='/client/cards/cardholder')return route.fulfill({json:{fields:{legal_last_name:'Holder',legal_first_name:'Alice',email:'offline@example.test',mobile:'13800138000',mobile_country_code:'+86'}}});
    return route.fulfill({json:path==='/bootstrap'?{...fixture.authenticated,locale:'en',user:signedIn?fixture.authenticated.user:null}:fixture.api[path]??{}});
   }
   try{return route.fulfill({body:readFileSync(root+(u.pathname==='/'?'/index.html':u.pathname)),contentType:u.pathname.endsWith('.js')?'text/javascript':u.pathname.endsWith('.css')?'text/css':'text/html'});}catch{return route.abort();}
  });
  await page.goto(origin+'/#/pages/messages/index?filter=platform&page=3');
  await page.locator('.row').first().waitFor();await page.screenshot({path:`${out}/${name}-list-before-detail.png`});console.log(name,'message list ready');
  await page.evaluate(()=>window.scrollTo(0,900));await page.waitForTimeout(100);
  const saved=await page.evaluate(()=>window.scrollY);assert.ok(saved>500);
  await page.locator('.row').nth(7).click();
  await page.locator('.message-detail').waitFor();console.log(name,'detail ready');mode='hold';
  await page.locator('[aria-label="Back"]').click();
  await page.locator('.row:visible').first().waitFor();console.log(name,'returned');await page.waitForTimeout(150);
  assert.match(page.url(),/filter=platform.*page=3/);
  assert.ok(Math.abs(await page.evaluate(()=>window.scrollY)-saved)<100,'return restores original list position while API held');
  assert.equal(await page.locator('.row:visible').count(),25,'refresh retains content');
  assert.match(await page.locator('.refresh-status').innerText(),/Updating/);
  await page.screenshot({path:`${out}/${name}-return-refresh.png`});
  mode='fail';for(const {route} of held.splice(0))await route.fulfill({status:503,json:{}});
  await page.getByText('Update failed. Please retry.',{exact:true}).waitFor();assert.equal(await page.locator('.row:visible').count(),25);
  mode='ok';await page.locator('.refresh-status').getByText('Retry',{exact:true}).click();await page.waitForFunction(()=>!document.querySelector('.refresh-status'));
  await page.locator('.toolbar .filter').filter({hasText:/^All$/}).click();await page.waitForTimeout(150);
  assert.ok(await page.evaluate(()=>window.scrollY)<100,'changing filter resets scroll');
  // Scope/authorization failure must discard all retained personal records.
  await page.locator('.row').first().click();await page.locator('.message-detail').waitFor();mode='deny';
  await page.locator('[aria-label="Back"]').click();await page.waitForTimeout(300);
  assert.equal(await page.locator('.row:visible').count(),0,'403 clears cached list');mode='ok';
  // Generic financial history keeps its exact page on detail return without financial POSTs.
  await page.goto(origin+'/#/pages/screen/index?path='+encodeURIComponent('/wallet/withdrawals?page=3'));
  await page.locator('.order').first().waitFor();await page.evaluate(()=>window.scrollTo(0,900));await page.waitForTimeout(100);
  const historyScroll=await page.evaluate(()=>window.scrollY);
  await page.locator('.order').nth(7).click();await page.locator('.receipt').waitFor();mode='hold';
  await page.locator('[aria-label="Back"]').click();await page.locator('.order:visible').first().waitFor();await page.waitForTimeout(100);
  assert.match(decodeURIComponent(page.url()),/withdrawals\?page=3/);
  assert.ok(Math.abs(await page.evaluate(()=>window.scrollY)-historyScroll)<100);
  assert.equal(await page.locator('.order:visible').count(),25);
  for(const {route} of held.splice(0))await route.fulfill({status:503,json:{}});
  await page.getByText('Update failed. Please retry.',{exact:true}).waitFor();assert.equal(await page.locator('.order:visible').count(),25);
  await page.screenshot({path:`${out}/${name}-history-refresh-failure.png`});mode='ok';
  // Server field errors appear once, beside the field, and focus the invalid editable field.
  await page.goto(origin+'/?case=topup#/pages/screen/index?path='+encodeURIComponent('/wallet/top-up'));
  const amount=page.locator('input[name="requested_amount"]');await amount.fill('20');await page.screenshot({path:`${out}/${name}-form-before-validation.png`});
  await page.getByText('Continue',{exact:true}).click();
  await page.getByText('The format is invalid.',{exact:true}).waitFor();
  assert.equal(await page.getByText('The format is invalid.',{exact:true}).count(),1);
  assert.equal(await amount.getAttribute('aria-invalid'),'true');
  assert.equal(await amount.evaluate(e=>e===document.activeElement),true);
  await page.screenshot({path:`${out}/${name}-inline-field.png`});
  // A teleported opening dialog scrolls its own body, keeping the fixed footer in place.
  await page.goto(origin+'/?case=cards#/pages/cards/index');
  await page.locator('.section-header .apply').click();await page.locator('.product .primary').first().click();
  const dialog=page.getByRole('dialog',{name:/Apply for a card/});await dialog.locator('.application').waitFor();
  await dialog.locator('input[name="legal_last_name"]').fill('Holder');await dialog.locator('input[name="legal_first_name"]').fill('Alice');
  await dialog.locator('input[name="email"]').fill('offline@example.test');await dialog.locator('input[name="mobile"]').fill('13800138000');
  await dialog.locator('.confirm-check').click();const outer=await page.evaluate(()=>window.scrollY);
  await dialog.getByText('Confirm and open',{exact:true}).click();
  await dialog.getByText('This field is required.',{exact:true}).waitFor();
  assert.equal(await dialog.getByText('This field is required.',{exact:true}).count(),1);
  assert.equal(await dialog.getByText('Enter a valid email address.',{exact:true}).count(),1);
  assert.equal(await dialog.locator('input[name="legal_last_name"]').evaluate(e=>e===document.activeElement),true);
  assert.equal(await page.evaluate(()=>window.scrollY),outer,'dialog error focus does not scroll parent page');
  await page.screenshot({path:`${out}/${name}-dialog-errors.png`});
  await dialog.locator('[aria-label="Close"]').click();
  await page.goto(origin+'/?case=transfer#/pages/screen/index?path='+encodeURIComponent('/wallet/transfer'));
  await page.getByText('Review transfer',{exact:true}).click();
  await page.getByText('Enter a positive transfer amount.',{exact:true}).waitFor();
  assert.equal(await page.locator('input[name="amount"]').evaluate(e=>e===document.activeElement),true);
  await page.locator('input[name="amount"]').fill('20');await page.getByText('Review transfer',{exact:true}).click();
  await page.getByText('The recipient has not completed identity registration and cannot receive transfers.',{exact:true}).waitFor();
  assert.equal(await page.locator('input[name="recipient_account_id"]').evaluate(e=>e===document.activeElement),true);
  assert.equal(writes.includes('/client/wallet/transfers'),false,'invalid transfer never submits money');
  await page.goto(origin+'/?case=withdraw#/pages/screen/index?path='+encodeURIComponent('/wallet/withdraw'));
  await page.locator('input[name="address"]').fill('T'+'1'.repeat(33));await page.locator('input[name="amount"]').fill('20');
  await page.locator('.submit').first().click();await page.getByText('Confirm withdrawal',{exact:true}).click();
  await page.getByText('The format is invalid.',{exact:true}).waitFor();
  assert.equal(await page.locator('input[name="address"]').evaluate(e=>e===document.activeElement),true);

  await page.goto(origin+'/?case=settings#/pages/screen/index?path='+encodeURIComponent('/account/settings'));
  await page.getByText('Log out',{exact:true}).click();
  await page.locator('input[name="email"]').waitFor();
  assert.equal(await page.locator('.cards-page:visible,.row:visible,.order:visible').count(),0,'logout discards prior user content');
  await page.locator('input[name="email"]').fill('offline@example.test');await page.locator('input[name="password"]').fill('offline-password');
  // Enter remains a supported way to submit the login form.
  await page.locator('input[name="password"]').press('Enter');
  await page.getByText('Enter a valid email address.',{exact:true}).waitFor();
  assert.equal(await page.getByText('Enter a valid email address.',{exact:true}).count(),1);
  assert.equal(await page.locator('input[name="email"]').evaluate(e=>e===document.activeElement),true);
  await page.screenshot({path:`${out}/${name}-login-errors.png`});
  assert.deepEqual(errors,[]);
  assert.equal(writes.includes('/client/cards/issues'),false,'invalid material never creates a card');
  console.log(`${name}: filter/page/scroll restoration, held and failed refresh, permission invalidation, generic financial history, logout clearing, login Enter, inline errors and teleported dialog focus passed (all intercepted fixtures)`);
 }finally{await browser.close();}
}
