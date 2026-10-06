import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Compiled administration with offline data; every request is intercepted.
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.setDefaultTimeout(15000);
    const writes = [], errors = [];
    let canExport = true, reject = true;
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            assert.equal(url.pathname, '/platform/asset-withdrawals/export');
            writes.push(request.postDataJSON());
            return route.fulfill(reject ? { status: 403, json: { message: 'Forbidden' } } : {
                contentType: 'text/csv; charset=UTF-8', headers: { 'Content-Disposition': 'attachment; filename="withdrawals-fixture.csv"' }, body: '\ufeff公司,账号\r\n离线公司,123456\r\n',
            });
        }
        const state = { component: 'platform/AssetOrders', url: '/platform/asset-withdrawals?company=' + company + '&asset=USDT&network=TRON&status=PENDING&page=2', version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['withdrawals.read', ...(canExport ? ['withdrawals.review'] : [])] }, user: null },
            i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN'], timezone: 'UTC', surface: 'platform' },
            mode: 'withdrawal', observations: [], statuses: ['PENDING', 'COMPLETED'],
            orders: { data: [], total: 26, current_page: 2, last_page: 2, prev_page_url: null, next_page_url: null },
            companies: [{ id: company, name: '离线公司' }], filters: { company, asset: 'USDT', network: 'TRON', status: 'PENDING', search: '123456', page: '2' },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + '/platform/asset-withdrawals');
    await page.getByRole('button', { name: '导出提现记录', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    assert.match(await dialog.innerText(), /26/);
    const download = dialog.getByRole('button', { name: '下载 CSV', exact: true });
    assert.equal(await download.isDisabled(), true);
    await dialog.getByLabel('当前密码').fill('offline-password');
    assert.equal(await download.isDisabled(), true);
    await dialog.getByRole('checkbox').check();
    await download.click();
    await dialog.getByRole('alert').waitFor();
    assert.equal(await dialog.getByLabel('当前密码').inputValue(), '');
    reject = false;
    await dialog.getByLabel('当前密码').fill('offline-password');
    const pending = page.waitForEvent('download');
    await download.click();
    assert.equal((await pending).suggestedFilename(), 'withdrawals-fixture.csv');
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(writes.length, 2);
    assert.deepEqual(writes[1], { company, asset: 'USDT', network: 'TRON', status: 'PENDING', search: '123456', password: 'offline-password', confirmed: true });
    canExport = false;
    await page.reload();
    await page.getByRole('heading', { name: '提现订单', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: '导出提现记录', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: applied filters without pagination, confirmation/password gates, error recovery, CSV download and permission visibility.');
} finally { await browser.close(); }
