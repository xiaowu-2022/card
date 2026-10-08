import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const id = '33333333-3333-4333-8333-333333333333';
const path = `/platform/tenants/${company}/kyc/${id}`;
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage();
    const writes = [], errors = [];
    let missing = true, reviewed = false, allowed = true;
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            assert.equal(url.pathname, path + '/review');
            writes.push(request.postDataJSON()); reviewed = true;
            return route.fulfill({ json: { reviewed: true } });
        }
        const state = { component: 'platform/KycDetail', url: path, version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['kyc.read', ...(allowed ? ['kyc.review'] : [])] }, user: null },
            i18n: { locale: 'en', enabledLocales: ['en'], timezone: 'UTC', surface: 'platform' },
            company: { id: company, name: 'Test company' }, canViewDocuments: false, canReview: allowed,
            application: { id, user: { id: 'member', displayName: 'Synthetic', contact: 'member@example.test' }, documentType: 'NATIONAL_ID', documentCountry: 'CN',
                maskedIdentityNumber: missing && !reviewed ? '—' : '**************002X', requiresIdentityNumber: missing && !reviewed,
                reviewStatus: reviewed ? 'APPROVED' : 'PENDING', ocrStatus: missing ? 'FAILED' : 'SUCCEEDED', processingStatus: reviewed ? 'COMPLETE' : 'FAILED',
                submittedAt: '2026-10-08T00:00:00Z', reviewedAt: reviewed ? '2026-10-08T01:00:00Z' : null },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + path);
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.getByText('Enter a valid identity number.', { exact: true }).waitFor();
    assert.equal(writes.length, 0);
    await page.getByRole('textbox', { name: 'Identity number', exact: true }).fill('11010519491231002x');
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
    assert.deepEqual(writes[0], { decision: 'approve', identity_number: '11010519491231002X' });
    missing = false; reviewed = false;
    await page.reload();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    assert.equal(await page.getByRole('textbox', { name: 'Identity number', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
    assert.deepEqual(writes[1], { decision: 'approve' });
    missing = true; reviewed = false;
    await page.reload();
    await page.locator('textarea').fill('Documents are unclear');
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
    assert.equal(writes[2].decision, 'reject');
    assert.equal('identity_number' in writes[2], false);
    reviewed = false; allowed = false;
    await page.reload();
    await page.getByRole('button', { name: 'Refresh status', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: missing-number approval, masked existing number, no-number rejection and review permission (offline fixtures).');
} finally { await browser.close(); }
