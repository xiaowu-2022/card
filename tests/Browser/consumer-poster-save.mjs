import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5232';
mkdirSync('artifacts/uni-parity/poster-save', {recursive:true});
for (const [name,engine,options] of [['chromium',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
 const browser = await engine.launch({headless:true,...options});
 try {
  const context = await browser.newContext({viewport:{width:390,height:780},isMobile:true,hasTouch:true});
  const page = await context.newPage();const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const dto = structuredClone(fixture.pages['/promotion']);dto.props.home.posterBackground=null;
  await context.route('**/*',route=>{
   const u=new URL(route.request().url());
   if(u.pathname.startsWith('/api/v1')){
    const key=u.pathname.slice(7);
    return route.fulfill({json:key==='/bootstrap'?{...fixture.authenticated,locale:'zh-CN'}:key==='/client/promotion'?dto:key==='/unread'?{messages:0,support:0}:fixture.api[key]??{}});
   }
   if(u.origin!==origin||route.request().method()!=='GET')return route.abort();
   return route.continue();
  });
  await page.goto(origin+'/?app_webview=1#/pages/screen/index?path=%2Fpromotion');
  await page.locator('.share-buttons uni-button').first().click();
  const dialog=page.getByRole('dialog');await dialog.locator('uni-image.preview').waitFor();
  const save=dialog.getByText('保存图片',{exact:true});
  await save.click();await dialog.getByText(/当前 App 版本不支持保存海报/).waitFor();
  await page.evaluate(()=>{
   window.posterSaves=[];
   window.__specpayPoster={version:1,save:data=>{window.posterSaves.push(data);return new Promise((resolve,reject)=>{window.posterResolve=resolve;window.posterReject=reject;});}};
  });
  await save.click();await dialog.getByText('正在保存海报…',{exact:true}).waitFor();
  assert.equal(await dialog.locator('.save-feedback').count(),0,'no premature success');
  assert.equal(await page.evaluate(()=>window.posterSaves.length),1);
  const encoded=await page.evaluate(()=>window.posterSaves[0]);assert.ok(encoded.startsWith('data:image/png;base64,iVBOR'));
  await page.evaluate(()=>window.posterResolve());await dialog.getByText('海报已保存到系统相册。',{exact:true}).waitFor();
  // A real sustained touch on the displayed image must use the same save path.
  await dialog.locator('uni-image.preview').scrollIntoViewIfNeeded();
  const box=await dialog.locator('uni-image.preview').boundingBox();
  await page.dispatchEvent('uni-image.preview','touchstart',{touches:[{identifier:1,clientX:box.x+20,clientY:box.y+20,pageX:box.x+20,pageY:box.y+20}],changedTouches:[{identifier:1,clientX:box.x+20,clientY:box.y+20,pageX:box.x+20,pageY:box.y+20}]});
  await page.waitForTimeout(600);
  await page.dispatchEvent('uni-image.preview','touchend',{touches:[],changedTouches:[{identifier:1,clientX:box.x+20,clientY:box.y+20,pageX:box.x+20,pageY:box.y+20}]});
  await page.waitForFunction(()=>window.posterSaves.length===2);
  await page.evaluate(()=>window.posterReject(new Error('denied')));
  await dialog.getByText(/海报保存失败.*相册权限/).waitFor();
  await page.screenshot({path:`artifacts/uni-parity/poster-save/${name}-denied.png`});
  assert.deepEqual(errors,[]);
  console.log(`PASS ${name}: old shell message, pending result, save success, long press and permission failure`);
 } finally {await browser.close();}
}
