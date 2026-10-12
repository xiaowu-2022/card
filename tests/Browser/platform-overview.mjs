import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

const origin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const output = 'artifacts/platform-overview';
mkdirSync(output, { recursive: true });
const amounts = { today: '123456789012.12345678', d3: '200000000000.00000001', d7: '300000000000', d15: '400000000000', d30: '500000000000', total: '900000000000.87654321' };
const fixture = {
    updatedAt: '2026-10-12T04:00:00Z', timezone: 'Asia/Kuala_Lumpur', rateAt: '2026-10-12T04:00:00Z', bubble: null,
    partners: { stock: '123456789012.12345678', theoretical: '500000000000.00000001', actual: '600000000000', difference: '-100000000000.00000001' },
    reimbursement: '-123456.00000001', inflow: { totals: amounts, native: { USDT: amounts } }, outflow: { totals: amounts, native: { USDT: amounts } },
    cards: { balance: '123456789012.12345678', today: '20', total: '99999999999' }, providerBalance: '333333333333.33',
    assets: { partner: '123456789012.12345678', ordinary: '234567890123.12345678' },
    daily: Object.fromEntries(['deposits', 'annual', 'depositRefunds', 'activation', 'rebates', 'annualCommission'].map((key) => [key, amounts])),
    alerts: { remaining: '100000000000.00000001', refund10: '200000000000.00000001', refund3: '30000000000.00000001' },
};

for (const [name, browserType] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await browserType.launch(name === 'chromium' ? { channel: 'chrome' } : {});
    try {
        const page = await browser.newPage();
        const errors = [];
        const unexpected = [];
        page.on('pageerror', (error) => errors.push(error.message));
        let locale = 'zh-CN';
        let state = fixture;
        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') {
                unexpected.push(request.method() + ' ' + url.pathname);
                return route.abort();
            }
            if (url.pathname.startsWith('/build/')) {
                return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
            }
            if (url.pathname !== '/platform/demo') {
                unexpected.push(url.pathname);
                return route.abort();
            }
            const props = {
                errors: {}, publicAssets: [], tenant: null, flash: {},
                auth: { admin: { name: 'Offline administrator', permissions: ['tenant.read', 'users.read', 'wallet.read', 'ledger.read', 'partners.manage', 'cards.read', 'wallet_topups.read', 'withdrawals.read', 'provider_operation.read'] }, user: null },
                i18n: { locale, enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Kuala_Lumpur', surface: 'platform' },
                companies: [{ id: '22222222-2222-4222-8222-222222222222', name: 'Offline company' }],
                filters: { start: '2026-09-13', end: '2026-10-12', scope: 'all', companies: [] },
                days: [], totals: { inflow: '0', outflow: '0', net: '0' }, financialAccess: { inflow: true, outflow: true, overflow: false }, overview: state,
            };
            const inertia = { component: 'platform/Dashboard', props, url: url.pathname, version: 'offline-fixture' };
            if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: inertia });
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(inertia).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
        });
        for (const language of ['zh-CN', 'en']) {
            locale = language;
            for (const width of [375, 768, 1440, 1920]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.goto(origin + '/platform/demo');
                const overview = page.locator('[data-platform-overview]');
                await overview.waitFor();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name} ${locale} ${width}: viewport overflow`);
                assert.equal(await overview.locator('tbody tr').count(), 5);
                assert.equal(await overview.locator('thead th').count(), 7);
                assert.equal(await overview.getByLabel(locale === 'zh-CN' ? '泡沫' : 'Bubble', { exact: true }).innerText(), '');
                assert.match(await overview.innerText(), /-100,000,000,000\.00000001/);
                assert.equal(await page.locator('details').first().getAttribute('open'), null);
                if (locale === 'zh-CN' && [375, 1440].includes(width)) await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
            }
        }
        locale = 'zh-CN';
        state = { ...fixture, assets: { partner: null, ordinary: '0' }, cards: { ...fixture.cards, balance: null }, providerBalance: null };
        await page.goto(origin + '/platform/demo');
        await page.getByText('暂不可估算', { exact: true }).first().waitFor();
        assert.equal(await page.getByText('暂不可估算', { exact: true }).count(), 2);
        assert.equal(await page.getByText('暂不可用', { exact: true }).count(), 1);
        await page.getByRole('button', { name: '刷新', exact: true }).click();
        await page.locator('[data-platform-overview]').waitFor();
        state = { updatedAt: fixture.updatedAt, timezone: fixture.timezone, rateAt: null, bubble: null };
        await page.goto(origin + '/platform/demo');
        await page.locator('[data-platform-overview]').waitFor();
        assert.equal(await page.getByText('合伙人总资产估值合计', { exact: true }).count(), 0);
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpected, []);
        console.log(`PASS ${name}: two languages, four widths, long exact amounts, negatives, blank bubble, unknown balances, refresh and gated sections; no external requests or mutations.`);
    } finally {
        await browser.close();
    }
}
