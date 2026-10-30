import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Complete local interception: no administrative or provider service is contacted.
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const oldId = '33333333-3333-4333-8333-333333333333';
const currentId = '44444444-4444-4444-8444-444444444444';
const retriedId = '55555555-5555-4555-8555-555555555555';
const userId = '66666666-6666-4666-8666-666666666666';
const detailPath = id => `/platform/tenants/${company}/kyc/${id}`;
const listPath = `/platform/kyc?company=${company}&status=FAILED&page=2`;
const supersededCopy = 'This application has been replaced by a newer submission. Open the current application to review it.';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    let phase = 'superseded', currentApproved = false;
    const reads = [], writes = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    const application = id => {
        const isSuperseded = id === oldId && ['superseded', 'stale-replaced'].includes(phase)
            || id === currentId && phase === 'retried';
        const reviewStatus = id === currentId && currentApproved ? 'APPROVED'
            : id === currentId && ['rejected', 'retried'].includes(phase) ? 'REJECTED'
            : id === oldId && phase === 'review-decided' ? 'APPROVED' : 'PENDING';
        return {
            id, companyId: company, companyName: 'Synthetic company',
            user: { id: userId, displayName: 'Synthetic', contact: 'synthetic@example.test' },
            documentType: 'NATIONAL_ID', documentCountry: 'CN', maskedIdentityNumber: '**************002X',
            requiresIdentityNumber: false, reviewStatus,
            processingStatus: reviewStatus === 'PENDING' ? 'FAILED' : 'COMPLETE',
            processingError: 'KYC_BACK_VALIDITY_UNRECOGNIZED', submittedAt: '2026-10-30T00:00:00Z', reviewedAt: null,
            isSuperseded, currentApplicationId: phase === 'retried' ? retriedId : isSuperseded ? currentId : id,
            reviewable: !isSuperseded && reviewStatus === 'PENDING',
            retryable: !isSuperseded && ['PENDING', 'REJECTED'].includes(reviewStatus),
        };
    };
    const state = (path, component = 'platform/Kyc', selected = oldId) => ({
        component, url: path, version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'synthetic-admin', name: 'Synthetic', scope: 'PLATFORM', permissions: ['users.read', 'kyc.read', 'kyc.review'] }, user: null },
            i18n: { locale: 'en', enabledLocales: ['en'], timezone: 'UTC', surface: 'platform' },
            companies: [{ id: company, name: 'Synthetic company' }], filters: { company, status: 'FAILED' },
            applications: { data: [application(oldId)], total: 21, current_page: 2, last_page: 2, prev_page_url: '/platform/kyc?page=1', next_page_url: null },
            application: application(selected), company: { id: company, name: 'Synthetic company' }, canReview: true, canViewDocuments: false,
        },
    });
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            const id = url.pathname.split('/').at(-2);
            const data = request.postDataJSON();
            writes.push({ id, path: url.pathname, data });
            if (phase === 'stale-replaced') return route.fulfill({ status: 409, json: { error: { code: 'KYC_APPLICATION_SUPERSEDED', message: 'SECRET provider body' } } });
            if (phase === 'review-conflict') {
                phase = 'review-decided';
                return route.fulfill({ status: 409, json: { error: { code: 'KYC_ALREADY_REVIEWED', message: 'SECRET contact consumer support' } } });
            }
            if (url.pathname.endsWith('/retry')) {
                assert.equal(phase, 'rejected');
                assert.equal(id, currentId);
                phase = 'retried';
                return route.fulfill({ status: 202, json: { applicationId: retriedId, processingStatus: 'QUEUED' } });
            }
            assert.equal(id, currentId, 'approval must target the explicitly opened application');
            currentApproved = true;
            return route.fulfill({ json: { reviewed: true } });
        }
        if (url.pathname === `/platform/tenants/${company}/users/${userId}/kyc`) {
            const selected = url.searchParams.get('application') ?? oldId;
            reads.push(selected);
            return route.fulfill({ json: {
                company: { id: company, name: 'Synthetic company' },
                user: { id: userId, displayName: 'Synthetic', email: 'synthetic@example.test' },
                platformVerified: false, canReview: true, canViewDocuments: false,
                applications: { items: [application(currentId), application(oldId)], page: 1, lastPage: 1, total: 2 },
                application: application(selected),
            } });
        }
        const selected = [oldId, currentId, retriedId].find(id => url.pathname === detailPath(id));
        if (selected && request.headers()['x-requested-with'] === 'XMLHttpRequest' && !request.headers()['x-inertia']) {
            reads.push(selected);
            return route.fulfill({ json: { application: application(selected), company: { id: company, name: 'Synthetic company' }, canReview: true, canViewDocuments: false } });
        }
        const dto = state(url.pathname + url.search, selected ? 'platform/KycDetail' : 'platform/Kyc', selected ?? oldId);
        if (url.pathname === '/platform/users') {
            dto.component = 'platform/Users';
            Object.assign(dto.props, {
                canViewKyc: true, canViewFunds: false, canViewTopups: false, canManageRestrictions: false,
                financialAccess: { balances: false, commission: false, withdrawals: false },
                users: { data: [{ id: userId, companyId: company, companyName: 'Synthetic company', accountId: '202601010001',
                    email: 'synthetic@example.test', status: 'ACTIVE', createdAt: '2026-10-30T00:00:00Z', promotionRank: 0, ordinaryMember: false, remark: null }],
                    total: 1, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
            });
        }
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: dto });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(dto)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    const acknowledge = async () => {
        const dialog = page.locator('[data-operation-result]');
        if (await dialog.isVisible()) await dialog.getByRole('button', { name: /^ok$/i }).click();
    };
    await page.goto(origin + listPath);
    await page.getByRole('button', { name: 'View details', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Review', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'View details', exact: true }).click();
    await page.getByText(supersededCopy, { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Retry verification', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Open current application', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    assert.equal(reads.at(-1), currentId);
    assert.equal(writes.length, 0);
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: /^ok$/i }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
    assert.equal(writes[0].id, currentId);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    assert.equal(page.url(), origin + listPath);

    phase = 'stale'; currentApproved = false;
    await page.reload();
    await page.getByRole('button', { name: 'Review', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    phase = 'stale-replaced';
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.locator('button').filter({ hasText: /^Open current application$/ }).waitFor();
    await acknowledge();
    assert.equal(writes.length, 2);
    assert.equal(writes[1].id, oldId);
    assert.equal(reads.at(-1), oldId, 'a conflict refreshes the selected history without switching the review target');
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    assert.ok(!(await page.locator('body').innerText()).includes('SECRET'));
    await page.getByRole('button', { name: 'Open current application', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    assert.equal(writes.length, 2, 'opening the current application performs only a GET');
    await page.getByRole('button', { name: 'Close', exact: true }).click();

    phase = 'review-conflict';
    await page.reload();
    await page.getByRole('button', { name: 'Review', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.locator('button').filter({ hasText: /^Refresh status$/ }).waitFor();
    await acknowledge();
    assert.ok(!(await page.locator('body').innerText()).includes('SECRET'));
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Close', exact: true }).click();

    phase = 'rejected';
    await page.goto(origin + detailPath(currentId));
    await page.getByRole('button', { name: 'Retry verification', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    await page.locator('textarea').fill('Explicitly retry the retained documents');
    await page.getByRole('button', { name: 'Retry verification', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: /^ok$/i }).click();
    await page.getByRole('button', { name: 'Open current application', exact: true }).waitFor();
    assert.equal(writes.at(-1).path, detailPath(currentId) + '/retry');
    assert.equal(await page.getByRole('button', { name: 'Retry verification', exact: true }).count(), 0);

    phase = 'superseded'; currentApproved = false;
    const beforeUserHistory = writes.length;
    await page.goto(origin + `/platform/users?company=${company}&kyc_company=${company}&kyc_user=${userId}&kyc_application=${oldId}`);
    await page.getByRole('button', { name: 'Open current application', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Approve', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Open current application', exact: true }).click();
    await page.getByRole('button', { name: 'Approve', exact: true }).waitFor();
    assert.equal(reads.at(-1), currentId);
    assert.equal(new URL(page.url()).searchParams.get('kyc_application'), currentId);
    assert.equal(writes.length, beforeUserHistory, 'user history selection must not approve either application');
    assert.deepEqual(errors, []);
    console.log('PASS: superseded history is read-only, explicit current GET precedes approval, stale review refreshes selected evidence, rejected retry and user history selection preserve evidence.');
} finally { await browser.close(); }
