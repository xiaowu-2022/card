// Offline H5 lifecycle integration. Native cookie APIs and PHP authentication
// have separate tests; this does not claim Android/iOS device acceptance.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture=addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const origin=process.env.UNI_PARITY_ORIGIN??'http://127.0.0.1:5229';
for(const [name,engine,options] of [['chromium',chromium,{channel:'chrome'}],['webkit',webkit,{}]]) {
    const browser=await engine.launch({headless:true,...options});
    try {
        const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
        const page=await context.newPage(), errors=[], requests=[];
        let signedIn=false;
        page.on('pageerror',e=>errors.push(e.message));
        await context.addCookies([{name:'consumer_remember',value:'opaque-http-only-test-credential',url:origin,httpOnly:true,sameSite:'Lax'}]);
        await page.addInitScript(()=>{
            window.sessionSignals=[];
            window.addEventListener('consumer-session-state',event=>window.sessionSignals.push(event.detail));
        });
        await context.route('**/*',route=>{
            const req=route.request(),url=new URL(req.url());
            if(url.pathname.startsWith('/api/v1')) {
                const key=url.pathname.slice(7);requests.push({key,headers:req.headers()});
                if(key==='/login') {signedIn=true;return route.fulfill({json:{csrfToken:'offline-csrf'}});}
                if(key==='/logout') {signedIn=false;return route.fulfill({status:204});}
                if(req.method()!=='GET') return route.fulfill({json:{}});
                if(key==='/bootstrap') return route.fulfill({json:{...(signedIn?fixture.authenticated:fixture.guest),locale:'en',csrfToken:'offline-csrf'}});
                if(key==='/unread') return route.fulfill({json:{messages:0,support:0}});
                if(key.startsWith('/client/')) return route.fulfill({json:fixture.pages[key.slice(7)]??{}});
                return route.fulfill({json:fixture.api[key]??{}});
            }
            if(url.origin!==origin||/^\/(images|storage)\//.test(url.pathname)||req.method()!=='GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin+'/?app_webview=1#/pages/login/index');
        await page.locator('.auth-root').waitFor();
        await page.waitForFunction(()=>window.sessionSignals.some(value=>value.signedIn===false));
        await page.getByLabel('Email address', {exact:true}).fill('offline@example.test');
        await page.locator('.password-field input').fill('SyntheticPassword123');
        await page.locator('.auth-submit').click();
        await page.waitForURL(/pages\/assets\/index/);
        await page.waitForFunction(()=>window.sessionSignals.some(value=>value.signedIn===true));
        await page.locator('.tabs .tab').filter({hasText:/^Me$/}).click();
        await page.waitForURL(/pages\/account\/index/);
        await page.locator('.settings-row').click();
        await page.locator('.signout').click();
        await page.waitForURL(/pages\/login\/index/);
        const state=await page.evaluate(()=>({signals:window.sessionSignals,cookies:document.cookie,storage:JSON.stringify(localStorage)}));
        assert.equal(state.signals.at(-1).signedIn,false);
        assert.ok(state.signals.every(value=>Object.keys(value).sort().join(',')==='signedIn,tenantId'));
        assert.ok(!state.cookies.includes('consumer_remember'));
        assert.ok(!state.storage.includes('SyntheticPassword')&&!state.storage.includes('opaque-http-only-test-credential'));
        assert.ok(requests.length>4&&requests.every(req=>req.headers['x-consumer-webview']==='1'));
        assert.ok(requests.every(req=>!req.headers.authorization));
        assert.deepEqual(errors,[]);
        await context.close();
        console.log(`PASS ${name}: marked H5 login/logout signals, no JS credential access, no token persistence or Bearer conversion`);
    } finally {await browser.close();}
}
