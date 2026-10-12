import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const origin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
mkdirSync('artifacts/platform-funds', { recursive: true });
for (const [name, engine] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch(name === 'chromium' ? { channel: 'chrome' } : {});
    try {
        const page = await browser.newPage();
        const errors = []; const requests = [];
        page.on('pageerror', e => errors.push(e.message));
        let locale = 'zh-CN'; let limited = false;
        await page.route('**/*', async route => {
            const req = route.request(); const url = new URL(req.url());
            if (req.method() !== 'GET') { requests.push(req.method()); return route.abort(); }
            if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
            if (url.pathname !== '/platform/funds') { requests.push(url.pathname); return route.abort(); }
            const current = Number(url.searchParams.get('page') || 1);
            const filters = Object.fromEntries(url.searchParams);
            const props = {
                errors: {}, publicAssets: [], tenant: null, flash: {},
                auth: { admin: { name: 'Offline', permissions: limited ? ['users.read', 'wallet.read'] : ['users.read', 'wallet.read', 'ledger.read', 'wallet_topups.read'] }, user: null },
                i18n: { locale, enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Kuala_Lumpur', surface: 'platform' },
                companies: [{ id: 'company-a', name: '示例公司' }], filters,
                assets: ['ETH', 'USDT'], events: [{ value: 'WALLET_TOPUP_CREDIT', label: 'Actual receipt' }],
                flows: { current_page: current, last_page: 3, total: 51, first_page_url: '/platform/funds?page=1', prev_page_url: current > 1 ? '/platform/funds?page=' + (current - 1) : null, next_page_url: current < 3 ? '/platform/funds?page=' + (current + 1) + '&' + new URLSearchParams(filters) : null,
                    data: [{ id: 'entry-' + current, companyName: '示例公司', asset: 'ETH', event: 'WALLET_TOPUP_CREDIT', kind: 'Actual receipt', time: '2026-10-12T03:00:00Z', reference: 'order-' + current,
                        movements: [{ id: 'p1', account: 'USER_AVAILABLE', amount: '0.000000000000000001', user: { id: 'u1', companyId: 'company-a', companyName: '示例公司', displayName: '客户甲', remark: '客户备注', email: 'a@example.test', accountId: '123456' } }, { id: 'p2', account: 'TENANT_TOPUP_CLEARING', amount: '-0.000000000000000001', user: null }] }] },
            };
            const inertia = { component: 'platform/Funds', props, url: url.pathname + url.search, version: 'offline' };
            if (req.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: inertia });
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css || []).map(f => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(inertia).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
        });
        for (locale of ['zh-CN', 'en']) {
            for (const width of [375, 768, 1440]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.goto(origin + '/platform/funds?company=company-a&asset=ETH&from=2026-10-12');
                await page.getByRole('heading', { name: locale === 'zh-CN' ? '资金流水' : 'Fund flows', exact: true }).waitFor();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                assert.match(await page.locator('tbody').innerText(), /客户备注/);
                assert.match(await page.locator('tbody').innerText(), /0\.000000000000000001/);
                await page.getByRole('navigation', { name: locale === 'zh-CN' ? '分页' : 'Pagination', exact: true }).getByRole('link', { name: '2', exact: true }).click();
                await page.waitForURL('**/*page=2*');
                assert.match(page.url(), /company=company-a/); assert.match(page.url(), /asset=ETH/);
                if (locale === 'zh-CN' && width === 1440) await page.screenshot({ path: 'artifacts/platform-funds/' + name + '.png', fullPage: true });
            }
        }
        limited = true;
        await page.goto(origin + '/platform/funds');
        await page.getByRole('heading', { name: 'Fund flows', exact: true }).waitFor();
        assert.equal(await page.getByRole('link', { name: 'Fund flows', exact: true }).count(), 0);
        assert.deepEqual(errors, []); assert.deepEqual(requests, []);
        console.log('PASS ' + name + ': native precision, names/remarks, responsive table, filter-preserving numbered pagination and scoped menu; offline GET only.');
    } finally { await browser.close(); }
}
