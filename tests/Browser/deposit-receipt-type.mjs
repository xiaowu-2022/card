import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const id = '33333333-3333-4333-8333-333333333333';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [], writes = [];
    let receiptType = 'ACTUAL', revision = 0, source = 'primary', automatic = false, canAdvance = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        let flash = {};
        if (request.method() === 'POST') {
            assert.equal(url.pathname, `/platform/tenants/${company}/deposit-orders/${source}/${id}/receipt-type`);
            const body = request.postDataJSON();
            assert.equal(body.confirmed, true);
            assert.equal(body.revision, revision);
            assert.equal(body.receipt_type, receiptType === 'ADVANCE' ? 'ACTUAL' : 'ADVANCE');
            assert.ok(!('amount' in body));
            writes.push(body);
            receiptType = body.receipt_type;
            revision++;
            flash = { success: 'Receipt type updated. Related totals have been refreshed.' };
        }
        const state = { component: 'platform/AssetOrders', url: '/platform/topups', version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash,
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['wallet_topups.read', 'wallet_topups.confirm', 'partners.manage'] }, user: null },
            i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN', 'en'], timezone: 'UTC', surface: 'platform' },
            mode: 'deposit', companies: [{ id: company, name: 'Test company' }], filters: {}, statuses: ['CREDITED'], observations: [],
            orders: { data: [{ id, legacy: source === 'primary', source, reference: 'TEST123', tenant_id: company, company: 'Test company', accountId: '123456', userEmail: 'member@example.test', asset: 'USDT', network: source === 'primary' ? 'TRON' : 'ETHEREUM', amount: '100.01000000', actualReceivedAmount: '89.12345678', status: 'CREDITED', created_at: '2026-10-11T00:00:00Z', arrival_at: '2026-10-11T00:01:00Z', operated_at: null, operator: null, fee: null, address: 'offline', tx_hash: '', canConfirm: false, canRecheck: false, manuallyConfirmed: !automatic, receiptType, receiptTypeRevision: revision, arrivalType: receiptType === 'ADVANCE' ? 'ADVANCE' : automatic ? 'AUTO' : 'MANUAL', canChangeReceiptType: true, canAdvance }], current_page: 1, last_page: 1, total: 1, from: 1, to: 1, links: [] },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    for (automatic of [false, true]) for (source of ['primary', 'asset']) {
        receiptType = 'ACTUAL'; revision = 0;
        await page.goto(origin + '/platform/topups');
        await page.getByRole('columnheader', { name: '到账类型', exact: true }).waitFor();
        for (const target of ['切换为预支金额', automatic ? '切换为自动到账' : '切换为手动确认']) {
            await page.getByRole('button', { name: target, exact: true }).click();
            const dialog = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: '更改到账类型' }) });
            await dialog.getByText('89.12345678', { exact: false }).waitFor();
            const submit = dialog.getByRole('button', { name: '确认保存' });
            assert.equal(await submit.isDisabled(), true);
            if (writes.length === 0) await page.screenshot({ path: 'output/deposit-receipt-type-confirmation.png' });
            await dialog.getByRole('checkbox', { name: '我确认更改到账类型。' }).check();
            await submit.click();
            await page.getByRole('alertdialog').getByRole('button').click();
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(receiptType, target === '切换为预支金额' ? 'ADVANCE' : 'ACTUAL');
        }
    }
    assert.equal(writes.length, 8);
    assert.notEqual(writes[0].request_id, writes[1].request_id);
    automatic = true;
    await page.goto(origin + '/platform/topups');
    await page.getByText('自动到账', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: '切换为预支金额' }).count(), 1);
    canAdvance = false;
    await page.goto(origin + '/platform/topups');
    assert.equal(await page.getByRole('button', { name: /切换为/ }).count(), 0);
    automatic = false; canAdvance = false;
    await page.goto(origin + '/platform/topups');
    await page.getByText('手动确认', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: /切换为/ }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('Deposit receipt type: both sources, confirmation, exact amount, refreshed classification and automatic origin preserved passed.');
} finally { await browser.close(); }
