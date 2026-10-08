import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.STOCK_PREVIEW_ORIGIN ?? 'http://127.0.0.1:5229';
for (const engine of [chromium, webkit]) {
    const browser = await engine.launch({headless:true, ...(engine === chromium ? {channel:'chrome'} : {})});
    try {
        for (const [ua, expected] of [['Mozilla/5.0 (Linux; Android 14)', 'https://download.example/app.apk'], ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'https://install.example/ios']]) {
            for (const account of [false, true]) {
            const context = await browser.newContext({userAgent:ua, viewport:{width:390,height:844}});
            const page = await context.newPage();
            const errors=[]; page.on('pageerror',e=>errors.push(e.message));
            let destination='', updated=false, refreshed=false;
            await context.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.href === expected) {destination=url.href;return route.fulfill({contentType:'text/html',body:'Offline download destination'});}
                if (url.pathname.startsWith('/api/v1')) {
                    const key=url.pathname.slice(7);
                    if(key==='/bootstrap') {
                        if(updated) refreshed=true;
                        return route.fulfill({json:{...(account ? fixture.authenticated : fixture.guest),locale:'en',appDownloads:{androidDownloadUrl:updated ? 'https://download.example/app.apk' : 'https://old.example/specpay.apk',iosDistributionUrl:updated ? 'https://install.example/ios' : 'https://old.example/ios'}}});
                    }
                    return route.fulfill({json:key==='/client/' ? fixture.pages['/'] : fixture.api[key] ?? {}});
                }
                if(url.origin!==origin)return route.abort();
                return route.continue();
            });
            await page.goto(origin+(account ? '/#/pages/account/index' : '/#/'));
            const link = account ? page.locator('.menu-item').filter({hasText:'Download app'}) : page.locator('.marketing-download');
            await link.waitFor();
            assert.equal(await link.innerText(),'Download app');
            if (account) {
                const labels = await page.locator('.menu-item').allTextContents();
                const downloadIndex = labels.findIndex(label => label.trim() === 'Download app');
                assert.equal(labels[downloadIndex+1].trim(), 'Customer support');
            }
            updated=true;
            await link.click();
            await page.waitForURL(expected);
            assert.equal(destination,expected); assert.equal(refreshed,true); assert.deepEqual(errors,[]);
            await context.close();
            }
        }
        console.log(`${engine.name()}: Android and iPhone download destinations passed`);
    } finally {await browser.close();}
}
