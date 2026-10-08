import { chromium, webkit } from 'playwright';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = 'http://127.0.0.1:5216';
for (const [name, engine] of [['chrome', chromium], ['webkit', webkit]]) {
 const browser = await engine.launch(name === 'chrome' ? {channel:'chrome'} : {});
 for (const width of [320, 375, 430, 768]) {
 const page = await browser.newPage({viewport:{width,height:900}});
 const dto = structuredClone(fixture.pages['/dashboard?fixture=verified']);
 dto.props.i18n.locale = 'zh-CN';
 dto.props.assetOverview.assets[0].available = '19690.01';
 const boot = structuredClone(fixture.authenticated); boot.locale = 'zh-CN';
 await page.route('**/*', route => {
   const u = new URL(route.request().url());
   if(u.pathname.startsWith('/api/v1')) {
    const key = u.pathname.replace('/api/v1','');
    return route.fulfill({json:key === '/bootstrap' ? boot : key.startsWith('/client') ? dto : fixture.api[key] ?? {messages:0,support:0}});
   }
   return u.origin === origin && route.request().method() === 'GET' ? route.continue() : route.abort();
 });
 await page.goto(origin + '/#/pages/assets/index');
 await page.locator('.account-tile').first().waitFor();
 // Simulate engines that ignore gap on flex containers, while retaining grid gaps.
 await page.evaluate(() => {
  for(const e of document.querySelectorAll('*')) if(getComputedStyle(e).display.includes('flex')) e.style.gap='0px';
 });
 const check = async expanded => {
 const result = await page.locator('.account-track').evaluate(el => {
  const boxes=[...el.children].map(e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,width:r.width}});
  const balance=el.querySelector('.tile-balance');
  const texts=balance.children;
  return {boxes, currencyGap:texts[1].getBoundingClientRect().left-texts[0].getBoundingClientRect().right, overflow:document.documentElement.scrollWidth>innerWidth, columns:getComputedStyle(el).gridTemplateColumns};
 });
 assert.equal(result.overflow,false);
 assert.ok(result.currencyGap>=3.9);
 for(let i=1;i<result.boxes.length;i++) if(result.boxes[i].y===result.boxes[i-1].y) assert.ok(result.boxes[i].x-result.boxes[i-1].right>=11.9);
 if(expanded && width===320) assert.equal(result.columns.split(' ').length,2);
 console.log(name,width,expanded?'expanded':'collapsed','PASS');
 };
 await check(false);
 await page.locator('.account-track').evaluate(el=>{el.scrollLeft=el.scrollWidth});
 assert.ok(await page.locator('.account-track').evaluate(el=>el.scrollWidth<=el.clientWidth || el.scrollLeft>0));
 await page.locator('.account-track').evaluate(el=>{el.scrollLeft=0});
 await page.screenshot({path:`artifacts/wallet-spacing-20261009/${name}-${width}.png`,fullPage:true});
 await page.locator('.account-panel .more').click();
 await page.locator('.account-track').evaluate(el=>el.style.removeProperty('gap'));
 await check(true);
 await page.close();
 }
 await browser.close();
}
