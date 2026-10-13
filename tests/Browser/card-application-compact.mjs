import assert from 'node:assert/strict';
import {readFileSync, mkdirSync} from 'node:fs';
import {chromium, webkit} from 'playwright';
const fixture=JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin='http://127.0.0.1:5237', root='dist/clients/specpay/release/h5', out='artifacts/card-application-compact';
mkdirSync(out,{recursive:true});
for(const [name,engine,launch] of [['chrome',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 for (const scenario of ['success', 'lost-material-response', 'fee-changed']) {
 const browser=await engine.launch({headless:true,...launch});
 try {
  const context=await browser.newContext({viewport:{width:375,height:812},isMobile:true,hasTouch:true}), page=await context.newPage();
  const dto=structuredClone(fixture.pages['/cards?fixture=verified']);
  dto.props.cardholder={state:'setup',id:null,requestId:null,productId:null,canSync:false};
  dto.props.kycApproved=true;dto.props.providerAvailable=true;dto.props.availableBalance='1000.00000000';dto.props.cards=[];dto.props.issueOrders=[];
  const product=dto.props.products[0];product.openingFee='5.12345678';product.readyForSetup=true;product.supportedFormFactors=['virtual_card'];product.notes='SCROLLABLE-PRODUCT-NOTES '.repeat(100);
  const writes=[], errors=[];let submittedFields={};
  page.on('pageerror',error=>errors.push(error.message));
  await context.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.origin!==origin) return route.abort();
   if(u.pathname.startsWith('/api/v1')) {
    const path=u.pathname.slice(7);
    if(path==='/bootstrap')return route.fulfill({json:{...fixture.authenticated,locale:'en'}});
    if(path==='/client/cards' && req.method()==='GET')return route.fulfill({json:dto});
    if(path==='/client/cards/cardholder' && req.method()==='POST') {
     const data=req.postDataJSON();writes.push({path,data});submittedFields=data;
     dto.props.cardholder={state:'ready',id:'11111111-1111-4111-8111-111111111111',requestId:data.request_id,productId:product.id,canSync:false,formFactor:'virtual_card'};
     if(scenario==='fee-changed') product.openingFee='7.00000000';
     if(scenario==='lost-material-response') return route.fulfill({status:503,json:{error:{code:'SERVER_ERROR'}}});
     return route.fulfill({json:{redirect:'/cards'}});
    }
    if(path.endsWith('/details') && req.method()==='POST') return route.fulfill({json:{fields:submittedFields}});
    if(path==='/client/cards/issues' && req.method()==='POST') { writes.push({path,data:req.postDataJSON()});return route.fulfill({json:{redirect:'/cards'}}); }
    if(req.method()!=='GET') { writes.push({path,data:req.postDataJSON()});return route.fulfill({json:{}}); }
    return route.fulfill({json:fixture.api[path]??{}});
   }
   if(req.method()!=='GET')return route.abort();
   try {const file=root+(u.pathname==='/'?'/index.html':u.pathname);return route.fulfill({body:readFileSync(file),contentType:file.endsWith('.html')?'text/html':file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'application/octet-stream'});}catch{return route.abort();}
  });
  await page.goto(origin+'/#/pages/cards/index');
  await page.locator('.section-header .apply').click();
  await page.locator('.product .primary').first().click();
  const dialog=page.getByRole('dialog').filter({has:page.locator('.application')});
  await dialog.locator('input[aria-label="Last name"]').fill('Holder');
  await dialog.locator('input[aria-label="First name"]').fill('Alice');
  await dialog.locator('input[aria-label="Email"]').fill('offline@example.test');
  await dialog.locator('input[aria-label="Phone number"]').fill('13800138000');
  assert.match(await dialog.locator('.fees').innerText(),/5\.12345678/);
  assert.match(await dialog.locator('.total').innerText(),/25\.12345678/);
  const button=dialog.getByText('Confirm and open',{exact:true});
  const checkbox=dialog.locator('.confirm-check');
  for(const width of [375,768,1036]) {
   await page.setViewportSize({width,height:812});
   const bottom=await button.boundingBox(), fields=await dialog.locator('.holder-fields').boundingBox(), check=await checkbox.boundingBox();
   assert.ok(bottom && bottom.y+bottom.height<=812,`${name}: button visible at ${width}`);
   assert.ok(fields && check && check.y+check.height<bottom.y,`${name}: all required inputs and confirmation visible at ${width}`);
   await page.screenshot({path:`${out}/${name}-${scenario}-${width}.png`});
  }
  assert.deepEqual(writes.filter(x=>x.path.includes('/cards')).map(x=>x.path),[]);
  await checkbox.click();
  await page.setViewportSize({width:375,height:640});
  await page.waitForFunction(() => document.querySelector('.modal-footer')?.getBoundingClientRect().bottom <= window.innerHeight);
  const activeBox=await button.boundingBox(), confirmationBox=await checkbox.boundingBox();
  assert.ok(activeBox && confirmationBox && activeBox.y+activeBox.height<=640 && confirmationBox.y+confirmationBox.height<activeBox.y);
  await page.screenshot({path:`${out}/${name}-${scenario}-375-confirmed.png`});
  await button.click();
  if(scenario==='lost-material-response') {
    await dialog.getByText('Refresh status',{exact:true}).waitFor();
    assert.equal(writes.filter(x=>x.path==='/client/cards/issues').length,0);
    await dialog.getByText('Refresh status',{exact:true}).click();
    await button.waitFor();await checkbox.click();await button.click();
  }
  if(scenario==='fee-changed') {
    await dialog.getByText('Card fees changed. Close and reopen the application to confirm the current fees.',{exact:true}).waitFor();
    assert.equal(writes.filter(x=>x.path==='/client/cards/issues').length,0);
    assert.deepEqual(errors,[]);console.log(`${name}: changed fee stops before issuing`);continue;
  }
  await page.waitForFunction(()=>!document.querySelector('.application'));
  assert.deepEqual(writes.filter(x=>x.path==='/client/cards/cardholder' || x.path==='/client/cards/issues').map(x=>x.path),['/client/cards/cardholder','/client/cards/issues']);
  const issue=writes.find(x=>x.path==='/client/cards/issues');
  assert.equal(Number(issue.data.initial_load_amount),Number(product.minimumInitialLoad));
  assert.equal(issue.data.cardholder_application_id,dto.props.cardholder.id);
  assert.deepEqual(errors,[]);
  console.log(`${name} ${scenario}: first-screen fields/fee confirmation, fixed footer, one-click material and issue flow passed`);
 }finally{await browser.close();}
 }
}
