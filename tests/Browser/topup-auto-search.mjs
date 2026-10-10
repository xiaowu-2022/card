import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const id = '33333333-3333-4333-8333-333333333333';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage();
    const writes = [], errors = [];
    let allow = true, legacy = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        let formErrors = {};
        if (request.method() === 'POST') {
            assert.equal(url.pathname, `/platform/tenants/${company}/topups/${id}/verify`);
            writes.push(request.postDataJSON());
            if (writes.length === 1) formErrors = { form: 'Blockchain verification is temporarily unavailable. No payment was confirmed.' };
        }
        const state = { component: 'platform/AssetOrders', url: '/platform/topups', version: 'fixture', props: {
            errors: formErrors, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['wallet_topups.read', ...(allow ? ['wallet_topups.verify'] : [])] }, user: null },
            i18n: { locale: 'en', enabledLocales: ['en'], timezone: 'UTC', surface: 'platform' },
            mode: 'deposit', companies: [{ id: company, name: 'Test company' }], filters: {}, statuses: ['PENDING'], observations: [],
            orders: { data: [{ id, legacy, source: legacy ? 'primary' : 'asset', reference: 'TEST123', tenant_id: company, company: 'Test company', accountId: '123456', userEmail: 'member@example.test', asset: 'USDT', network: legacy ? 'TRON' : 'ETHEREUM', amount: '10.06000000', status: 'PENDING', created_at: '2026-10-10T00:00:00Z', arrival_at: null, operated_at: null, operator: null, fee: null, address: 'offline', tx_hash: '', canConfirm: false, canRecheck: true, manuallyConfirmed: false }], current_page: 1, last_page: 1, total: 1, from: 1, to: 1, links: [] },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    async function open() {
        await page.goto(origin + '/platform/topups');
        await page.getByRole('button', { name: 'View details', exact: true }).click();
    }
    await open();
    await page.getByRole('button', { name: 'Recheck transfer', exact: true }).click();
    const search = page.getByRole('button', { name: 'Automatically search', exact: true });
    const hash = page.getByRole('button', { name: 'Verify by hash', exact: true });
    assert.equal(await search.isDisabled(), true);
    await page.getByRole('checkbox', { name: 'I confirm the order details.' }).check();
    assert.equal(await search.isEnabled(), true);
    assert.equal(await hash.isDisabled(), true);
    assert.equal(writes.length, 0);
    await search.click();
    await page.getByRole('alert').filter({ hasText: 'Blockchain verification is temporarily unavailable.' }).waitFor();
    assert.equal(writes[0].verification_mode, 'SEARCH');
    assert.equal(writes[0].tx_hash, null);
    assert.equal(writes[0].confirmed, true);
    // Even a filled hash is omitted when the user explicitly selects search.
    await page.getByPlaceholder('Transaction hash').fill('a'.repeat(64));
    await search.click();
    await search.waitFor({ state: 'hidden' });
    assert.equal(writes[1].verification_mode, 'SEARCH');
    assert.equal(writes[1].tx_hash, null);
    assert.equal(writes[1].request_id, writes[0].request_id);
    await page.getByRole('button', { name: 'Recheck transfer', exact: true }).click();
    await page.getByRole('checkbox', { name: 'I confirm the order details.' }).check();
    await hash.click();
    await hash.waitFor({ state: 'hidden' });
    assert.equal(writes[2].verification_mode, 'HASH');
    assert.equal(writes[2].tx_hash, 'a'.repeat(64));
    assert.notEqual(writes[2].request_id, writes[1].request_id);
    legacy = false;
    await open();
    await page.getByRole('button', { name: 'Recheck transfer', exact: true }).click();
    assert.equal(await search.count(), 0);
    allow = false;
    await open();
    assert.equal(await page.getByRole('button', { name: 'Recheck transfer', exact: true }).count(), 0);
    assert.equal(writes.length, 3);
    assert.deepEqual(errors, []);
    console.log('PASS: explicit empty-hash search, retry after unavailable, distinct hash verification, scoped visibility; all network traffic intercepted.');
} finally {
    await browser.close();
}
