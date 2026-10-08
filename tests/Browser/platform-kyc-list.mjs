import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const id = '33333333-3333-4333-8333-333333333333';
const detailPath = `/platform/tenants/${company}/kyc/${id}`;
const listPath = `/platform/kyc?company=${company}&page=2`;
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    let reviewed = false, allowed = true, reads = 0;
    const writes = [], errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            assert.equal(url.pathname, detailPath + '/review');
            writes.push(request.postDataJSON()); reviewed = true;
            return route.fulfill({ json: { reviewed: true } });
        }
        const application = { id, companyId: company, companyName: 'Test company', user: { id: 'member', displayName: 'Synthetic', contact: 'member@example.test' }, documentType: 'NATIONAL_ID', documentCountry: 'CN', maskedIdentityNumber: reviewed ? '**************002X' : '—', requiresIdentityNumber: !reviewed, reviewStatus: reviewed ? 'APPROVED' : 'PENDING', processingStatus: 'FAILED', submittedAt: '2026-10-08T00:00:00Z', reviewedAt: null };
        if (url.pathname === detailPath) {
            reads++;
            return route.fulfill({ json: { application, company: { id: company, name: 'Test company' }, canViewDocuments: false, canReview: allowed } });
        }
        const state = { component: 'platform/Kyc', url: listPath, version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['kyc.read', ...(allowed ? ['kyc.review'] : [])] }, user: null },
            i18n: { locale: 'en', enabledLocales: ['en'], timezone: 'UTC', surface: 'platform' },
            companies: [{ id: company, name: 'Test company' }], filters: { company },
            applications: { data: [application], total: 21, current_page: 2, last_page: 2, prev_page_url: '/platform/kyc?page=1', next_page_url: null },
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + listPath);
    await page.getByRole('link', { name: 'KYC', exact: true }).waitFor();
    assert.equal(reads, 0);
    await page.getByRole('button', { name: 'View details', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh status', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    assert.equal(writes.length, 0);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'Review', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    assert.equal(await dialog.getAttribute('data-admin-detail-drawer'), null);
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.getByText('Enter a valid identity number.', { exact: true }).waitFor();
    assert.equal(writes.length, 0);
    await page.getByRole('textbox', { name: 'Identity number', exact: true }).fill('11010519491231002x');
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    await dialog.getByRole('button', { name: 'Refresh status', exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    assert.equal(page.url(), origin + listPath);
    assert.deepEqual(writes, [{ decision: 'approve', identity_number: '11010519491231002X' }]);
    assert.equal(await page.getByRole('button', { name: 'Review', exact: true }).count(), 0);
    allowed = false; reviewed = false;
    await page.reload();
    await page.getByRole('button', { name: 'View details', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Review', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: standalone KYC navigation, lazy read-only view, review modal, identity validation, preserved list state and review permissions.');
} finally { await browser.close(); }
