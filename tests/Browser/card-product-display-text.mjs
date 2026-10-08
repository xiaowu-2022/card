import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const origin = 'http://card-product.test';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const id = '11111111-1111-4111-8111-111111111111';
for (const [name, engine, launch] of [['chrome', chromium, {channel: 'chrome'}], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({headless: true, ...launch});
    try {
        const page = await browser.newPage({viewport: {width: 375, height: 812}});
        let saved;
        await page.route('**/*', route => {
            const req = route.request(), url = new URL(req.url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname.startsWith('/build/')) return route.fulfill({body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'});
            if (req.method() === 'PUT') {
                saved = req.postDataJSON();
                return route.fulfill({status: 422, json: {errors: {name: 'Offline fixture: no save performed'}}});
            }
            const state = {component: 'platform/CardProducts', url: '/platform/card-products?edit=' + id, version: 'fixture', props: {
                errors: {}, publicAssets: [], tenant: null, flash: {}, requestId: 'fixture',
                auth: {admin: {name: 'Synthetic', permissions: ['card_product.manage']}, user: null},
                i18n: {locale: 'zh-CN', enabledLocales: ['en', 'zh-CN'], timezone: 'UTC', surface: 'platform'},
                cardProviders: [], products: [{id, name: 'Synthetic card', provider: 'PHOTONPAY', providerProductRef: '123456', cardProviderReferenceId: null,
                    cardProviderName: null, routingLocked: true, cardType: 'REGULAR', cardCurrency: 'USD', minimumInitialLoad: '20.00000000',
                    minimumReload: '20.00000000', openingFee: '5.00000000', monthlyFeeText: 'First month free', notes: 'No ATM withdrawals.\nOnline only.',
                    balanceLimit: null, status: 'ACTIVE', tenantConfigCount: 1}],
            }};
            return route.fulfill({contentType: 'text/html', body: `<!doctype html><html><head>${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`});
        });
        await page.goto(origin + '/platform/card-products?edit=' + id);
        const dialog = page.getByRole('dialog');
        await dialog.waitFor();
        const monthly = dialog.getByLabel('月费', {exact: true}), notes = dialog.getByLabel('卡片备注', {exact: true});
        assert.equal(await monthly.inputValue(), 'First month free');
        assert.equal(await notes.inputValue(), 'No ATM withdrawals.\nOnline only.');
        await monthly.fill('首月免费，之后每月 2 USD');
        await notes.fill('只支持线上消费\n不支持 ATM 提现');
        await Promise.all([page.waitForResponse(response => response.request().method() === 'PUT'), dialog.getByRole('button', {name: '保存', exact: true}).click()]);
        assert.equal(saved.monthly_fee_text, '首月免费，之后每月 2 USD');
        assert.equal(saved.notes, '只支持线上消费\n不支持 ATM 提现');
        assert.equal(saved.opening_fee, '5.00000000');
        console.log('PASS', name, 'prefill, multiline text and unchanged opening fee');
    } finally { await browser.close(); }
}
