import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const user = '33333333-3333-4333-8333-333333333333';
const cell = { count: 2, amount: '123456789.12345678', minimum: '1', maximum: '20' };
const summary = {
    registeredMembers: { direct: 3, indirect: 4 },
    teamByLevel: [0, 1, 2].map(rank => ({ rank, direct: rank + 1, indirect: rank + 2 })),
    directPeople: 9, indirectPeople: 13,
    totals: { ANNUAL: '123456789.12345678', ACTIVATION: '42.00000001' },
    tables: Object.fromEntries(['ANNUAL', 'ACTIVATION'].map(kind => [kind, [0, 1, 2].map(rank => ({ rank, direct: cell, indirect: cell }))])),
};
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [], reads = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        assert.equal(request.method(), 'GET');
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (url.pathname.endsWith('/report')) {
            reads.push(url);
            const kind = url.searchParams.get('kind');
            return route.fulfill({ json: { account: { companyName: 'Fixture company', accountId: '123456', displayName: 'Fixture member', email: null }, timezone: 'UTC', commission: '-10.00000001', summary,
                details: kind ? { kind, rank: Number(url.searchParams.get('rank')), page: Number(url.searchParams.get('page')), lastPage: 2, total: 31, hasMore: url.searchParams.get('page') === '1', items: [{ id: 'award', accountId: '654321', displayName: 'Source member', email: null, direct: true, sourceAmount: '10', rate: '20', amount: '2.00000001', occurredAt: '2026-10-12T00:00:00Z' }] } : null,
            } });
        }
        if (url.pathname !== '/platform/invitation-data') return route.abort();
        const selected = url.searchParams.has('subject') ? { id: user, tenant_id: company, account_id: '123456', display_name: 'Fixture member' } : null;
        const category = url.searchParams.get('category') || 'all';
        const state = { component: 'platform/InvitationData', url: url.pathname + url.search, version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['users.read', 'ledger.read', 'partners.manage'] }, user: null },
            i18n: { locale: 'en', enabledLocales: ['en', 'zh-CN'], timezone: 'UTC', surface: 'platform' },
            companies: [{ id: company, name: 'Fixture company' }], companyId: company, category, search: '', ranks: [0, 1, 2], selected,
            accounts: { current_page: Number(url.searchParams.get('page') || 1), last_page: 2, total: 21, data: [{ userId: user, tenant_id: company, company_name: 'Fixture company', account_id: '123456', display_name: 'Fixture member', partner_id: category === 'partners' ? 'partner' : null, userInfo: { id: user, companyId: company, companyName: 'Fixture company', accountId: '123456', displayName: 'Fixture member' }, commission: '-10.00000001', summary }] },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + '/platform/invitation-data?company=' + company + '&category=all&page=2');
    await page.getByRole('button', { name: 'Details', exact: true }).waitFor();
    assert.equal(reads.length, 0);
    await page.getByLabel('Account category', { exact: true }).selectOption('partners');
    await page.waitForURL('**category=partners**');
    await page.getByRole('button', { name: '2', exact: true }).click();
    await page.waitForURL('**page=2**');
    await page.getByRole('button', { name: 'Details', exact: true }).click();
    const drawer = page.getByRole('dialog');
    await drawer.getByText('Registered member (not activated)', { exact: true }).waitFor();
    await drawer.getByRole('button', { name: /Mastercard level 1/ }).click();
    await drawer.getByText('Reward rate / difference', { exact: false }).first().waitFor();
    await drawer.getByRole('button', { name: 'View records', exact: true }).first().click();
    await drawer.getByRole('heading', { name: /Mastercard level 1/ }).waitFor();
    const secondPage = page.waitForResponse(response => response.url().includes('/report?') && new URL(response.url()).searchParams.get('page') === '2');
    await drawer.getByRole('button', { name: '2', exact: true }).click();
    await secondPage;
    await page.waitForFunction(() => location.search.includes('invitation_page=2'));
    assert.equal(reads.at(-1).searchParams.get('page'), '2');
    await page.reload();
    await page.getByRole('dialog').getByRole('heading', { name: /Mastercard level 1/ }).waitFor();
    assert.equal(reads.at(-1).searchParams.get('page'), '2');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.waitForFunction(() => !location.search.includes('subject='));
    assert.equal(new URL(page.url()).searchParams.get('category'), 'partners');
    assert.equal(new URL(page.url()).searchParams.get('page'), '2');
    const output = 'artifacts/platform-invitation-data-20261012'; mkdirSync(output, { recursive: true });
    for (const width of [375, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: `${output}/${width}.png`, fullPage: true });
    }
    assert.deepEqual(errors, []);
    console.log('PASS: account filters, list/detail numbered pagination, lazy invitation records, reload and preserved drawer/list URL state, responsive overflow and no writes.');
} finally { await browser.close(); }
