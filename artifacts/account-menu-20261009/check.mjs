import { chromium, webkit } from 'playwright';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const f=JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin='http://127.0.0.1:5217';
for(const [name,engine] of [['chrome',chromium],['webkit',webkit]]) {
 const browser=await engine.launch(name==='chrome'?{channel:'chrome'}:{});
 for(const width of [320,375,430]) {
 const page=await browser.newPage({viewport:{width,height:850}});
 const boot=structuredClone(f.authenticated);boot.locale='zh-CN';
 await page.route('**/*',route=>{
  const u=new URL(route.request().url());
  if(u.pathname.startsWith('/api/v1')) {
   const key=u.pathname.replace('/api/v1','');
   return route.fulfill({json:key==='/bootstrap'?boot:key.startsWith('/client')?f.pages['/account/security']:f.api[key]??{messages:0,support:0}});
  }
  return u.origin===origin&&route.request().method()==='GET'?route.continue():route.abort();
 });
 await page.goto(origin+'/#/pages/account/index');
 await page.getByText('账户安全',{exact:true}).waitFor();
 assert.equal(await page.getByText('账户与安全',{exact:true}).count(),0);
 const result=await page.evaluate(()=>({
  overflow:document.documentElement.scrollWidth>innerWidth,
  menus:[...document.querySelectorAll('.menu-item')].map(e=>({font:getComputedStyle(e).fontSize,height:e.getBoundingClientRect().height,overflow:e.scrollWidth>e.clientWidth})),
  settings:getComputedStyle(document.querySelector('.settings-row')).fontSize
 }));
 assert.equal(result.overflow,false);
 assert.equal(result.settings,'13px');
 for(const menu of result.menus){assert.equal(menu.font,'12px');assert.ok(menu.height>=88);assert.equal(menu.overflow,false);}
 await page.screenshot({path:`artifacts/account-menu-20261009/${name}-${width}.png`,fullPage:true});
 await page.locator('.menu-item').filter({hasText:'账户安全'}).click();
 await page.waitForURL(url=>decodeURIComponent(decodeURIComponent(url.href)).includes('/account/security')); 
 console.log(name,width,'PASS');
 await page.close();
 }
 await browser.close();
}
