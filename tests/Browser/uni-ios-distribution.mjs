import assert from 'node:assert/strict';
import {readFileSync,mkdirSync} from 'node:fs';
import {chromium,webkit} from 'playwright';
const fixture=JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json','utf8'));
const root=process.env.IOS_DISTRIBUTION_ROOT ?? 'public/h5',origin='https://distribution.example.test';
mkdirSync('output/uni-ios-distribution',{recursive:true});
for(const [name,engine,options] of [['chromium',chromium,{channel:'chrome'}],['webkit',webkit,{}]]){
 const browser=await engine.launch({headless:true,...options});
 try{
  const page=await browser.newPage({viewport:{width:390,height:844},userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'});
  const errors=[],requests=[];let release,metadataCalls=0;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());requests.push(url.pathname+url.search);
   if(url.origin!==origin){assert.equal(req.isNavigationRequest(),false,'opening the distribution page stays on the current origin');return route.abort();}
   if(url.pathname==='/ipa/index.php'){
    assert.equal(url.search,'?format=json');assert.equal(req.headers().authorization,undefined);assert.equal(req.headers().cookie,undefined);
    metadataCalls++;
    if(metadataCalls===1)await new Promise(resolve=>{release=resolve;});
    if(metadataCalls===1)return route.fulfill({status:503,json:{}});
    return route.fulfill({json:{packages:[{title:'U 卡',file:'U card.ipa',version:'2.6.21',build:'2621',size:9816000,downloadUrl:'https://cdn.example.test/app.ipa'}],unavailable:0}});
   }
   if(url.pathname==='/api/mobile/v1/bootstrap')return route.fulfill({json:{...fixture.guest,locale:'zh-CN',unread:null,publicAssets:{}}});
   if(url.pathname.startsWith('/api/mobile/v1/client'))return route.fulfill({json:fixture.pages['/']});
   if(url.pathname.startsWith('/api/'))return route.fulfill({json:{}});
   try{const p=root+(url.pathname==='/'?'/index.html':url.pathname);return route.fulfill({body:readFileSync(p),contentType:p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'text/html'});}catch{return route.abort();}
  });
  await page.goto(origin+'/#/pages/screen/index?path=%2F');
  await page.getByRole('link',{name:/下载 App|Download app/}).first().waitFor();
  const bootstrapBefore=requests.filter(p=>p.startsWith('/api/mobile/v1/bootstrap')).length;
  await page.getByRole('link',{name:/下载 App|Download app/}).first().click();
  await page.locator('.install-methods a').first().waitFor();
  assert.match(page.url(),/#\/pages\/app-download\/index$/);
  assert.equal(await page.locator('.install-methods a').count(),3);
  assert.deepEqual((await page.locator('.install-methods a').allTextContents()).map(text=>text.trim()),['app','轻量版app','便携版app']);
  assert.doesNotMatch(await page.locator('.install-methods').innerText(), /\b(?:ipa|webclip|pwa)\b/i);
  assert.equal(await page.locator('.install-methods a').nth(1).getAttribute('href'),'/webclip/');
  assert.equal(await page.locator('.install-methods a').nth(2).getAttribute('href'),'/pwa/');
  assert.equal(metadataCalls,0,'chooser never loads IPA metadata');
  assert.equal(await page.locator('.marketing-desktop-nav').count(),0);
  assert.equal(await page.locator('.install-main h1, .install-eyebrow, .install-tagline').count(),0);
  for (const [width,height] of [[320,480],[375,600],[390,650],[768,844],[1440,844]]) {
   await page.setViewportSize({width,height});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
   for (const button of await page.locator('.install-methods a').all()) {
    const box=await button.boundingBox();
    assert.ok(box && box.y>=0 && box.y+box.height+3<=height, `${name} ${width}x${height}: each installation button fits fully on the first screen`);
   }
   await page.screenshot({path:'output/uni-ios-distribution/'+name+'-chooser-'+width+'.png',fullPage:true});
  }
  await page.locator('.install-methods a').first().click();
  await page.getByRole('heading',{name:'U 卡，随身同行。'}).waitFor();
  assert.match(page.url(),/#\/pages\/app-download\/ipa$/);
  await page.getByText('正在读取安装包…',{exact:true}).waitFor();
  assert.equal(requests.filter(p=>p.startsWith('/api/mobile/v1/bootstrap')).length,bootstrapBefore,'no full bootstrap before iOS navigation');
  while(!release)await new Promise(resolve=>setTimeout(resolve,10));
  release();await page.getByText('重试',{exact:true}).click();
  await page.getByText('安装到 iPhone / iPad',{exact:true}).waitFor();
  assert.equal(await page.getByRole('link',{name:'下载 IPA 文件'}).count(),0);
  assert.equal(requests.some(p=>p==='/ipa'||p==='/ipa/'),false);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.deepEqual(errors,[]);
  await page.screenshot({path:'output/uni-ios-distribution/'+name+'.png',fullPage:true});
  console.log('PASS '+name+': immediate internal iOS page, independent metadata loading/retry, no bootstrap or directory redirect');
 }finally{await browser.close();}
}
