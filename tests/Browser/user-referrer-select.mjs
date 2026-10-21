import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { readFileSync, mkdirSync } from 'node:fs';
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const styles = (manifest['resources/js/app.tsx']?.css ?? []).map(p => `<link rel="stylesheet" href="/build/${p}">`).join('');
const server = await createServer({ configFile:false, root:process.cwd(), resolve:{alias:{'@':resolve('resources/js')}}, plugins:[react(), {
 name:'referrer-fixture', configureServer(s){s.middlewares.use('/__referrer',async (_req,res)=>{res.setHeader('Content-Type','text/html');res.end(await s.transformIndexHtml('/__referrer',`<html><head>${styles}</head><body><div id="root"></div><script type="module" src="/referrer-fixture.tsx"></script></body></html>`));});},
 resolveId(id){if(id.endsWith('/referrer-fixture.tsx'))return resolve('referrer-fixture.tsx');},
 load(id){if(id===resolve('referrer-fixture.tsx'))return `import React,{useState} from 'react';import {createRoot} from 'react-dom/client';import {UserReferrerSelect} from '/resources/js/components/admin/UserReferrerSelect';import {clientI18n} from '/resources/js/i18n';await clientI18n.changeLanguage('zh-CN');function Fixture(){const [value,setValue]=useState(null);const [confirmed,setConfirmed]=useState(false);return <main style={{padding:24,maxWidth:600}}><h1>修改推荐人</h1><UserReferrerSelect url="/platform/tenants/company/users/user/referrer" value={value} onChange={v=>{setValue(v);setConfirmed(false)}} onBrowse={()=>setConfirmed(false)}/><label>原因<textarea aria-label="原因"/></label><label><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/>确认</label><output>{value?.id}</output></main>};createRoot(document.getElementById('root')).render(<Fixture/>);`;}
}],server:{host:'127.0.0.1',port:5249,strictPort:true,watch:{ignored:['**/mobile/**','**/public/**','**/storage/**','**/output/**','**/artifacts/**']}}});
await server.listen();const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];const queries=[];let failed=false;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/referrer/candidates?**',async route=>{const q=new URL(route.request().url()).searchParams;const search=q.get('search');queries.push(search);if(search==='retry'&&!failed){failed=true;return route.fulfill({status:503,body:'unavailable'});}return route.fulfill({json:{items:[{id:q.get('page')==='2'?'member-2':'member-1',account_id:'ACC1001',email:'orchid@example.test',display_name:search||'Orchid'}],page:Number(q.get('page')),hasMore:q.get('page')==='1'}});});
 await page.goto('http://127.0.0.1:5249/__referrer');await page.getByLabel('原因',{exact:true}).fill('Keep this reason');
 const trigger=page.locator('button[role="combobox"]');await trigger.click();
 const input=page.locator('[cmdk-input]');await page.getByRole('option').first().waitFor();
 for(const term of ['orchid@example','Orchid','1001']){await input.fill(term);await page.getByRole('option',{name:new RegExp(term.replace('@','\\@'))}).waitFor();}
 await page.getByRole('option').first().click();assert.equal(await page.locator('output').textContent(),'member-1');assert.equal(await page.getByLabel('原因',{exact:true}).inputValue(),'Keep this reason');
 await page.getByRole('checkbox').check();await trigger.click();assert.equal(await page.locator('input[type=checkbox]').isChecked(),false);await page.getByRole('option').first().waitFor();
 await page.locator('button.partner-select-more').click();await page.waitForFunction(()=>document.querySelectorAll('[cmdk-item]').length===2);
 await input.fill('retry');await page.locator('button.partner-select-more').waitFor();await page.locator('button.partner-select-more').click();await page.getByRole('option',{name:/retry/}).waitFor();
 assert.equal(await page.locator('textarea').inputValue(),'Keep this reason');assert.equal(page.url(),'http://127.0.0.1:5249/__referrer');assert.deepEqual(errors,[]);assert.ok(queries.includes('orchid@example')&&queries.includes('1001'));
 mkdirSync('artifacts/referrer-search',{recursive:true});await page.screenshot({path:'artifacts/referrer-search/admin-390.png',fullPage:true});
 console.log('Referrer dropdown passed: fuzzy remote search, selection, retained reason, confirmation reset, pagination, retry and no navigation.');
}finally{await browser.close();await server.close();}
