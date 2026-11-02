import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// Every HTTP request and clipboard write is intercepted locally. No OCR, storage,
// provider, production service or operating-system clipboard is contacted.
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const tenantId = '22222222-2222-4222-8222-222222222222';
const applicationId = '33333333-3333-4333-8333-333333333333';
const newerId = '44444444-4444-4444-8444-444444444444';
const userId = '55555555-5555-4555-8555-555555555555';
const frontImage = '66666666-6666-4666-8666-666666666666';
const backImage = '77777777-7777-4777-8777-777777777777';
const detailPath = id => `/platform/tenants/${tenantId}/kyc/${id}`;
const userPath = `/platform/tenants/${tenantId}/users/${userId}/kyc`;
const historyPath = `/platform/users?company=${tenantId}&kyc_company=${tenantId}&kyc_user=${userId}&kyc_application=${applicationId}`;
const password = 'offline-fixture-password';
const out = 'output/kyc-permanent-original-links';
const originalUrl = (id, side) => `${origin}/media/images/${side === 'front' ? frontImage : backImage}?profile=original&signature=${(id === newerId ? 'b' : 'a').repeat(64)}`;
mkdirSync(out, { recursive: true });

for (const [name, engine, options] of [['chrome', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 960 } });
        await context.addInitScript(() => {
            window.__originalClipboard = [];
            window.__originalClipboardFail = false;
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: async value => {
                        if (window.__originalClipboardFail) throw new Error('Offline clipboard rejection');
                        window.__originalClipboard.push(value);
                    },
                },
            });
        });
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [], documentRequests = [], reviewRequests = [], originalGets = [];
        let recent = false, allowed = true, reviewed = false, documentType = 'NATIONAL_ID';
        let originalMode = 'normal', previewMode = 'normal', noApplications = false;
        let releaseOriginal, delayedStarted, delayedFinished;
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        const application = id => ({
            id, companyId: tenantId, companyName: 'Offline synthetic company',
            user: { id: userId, displayName: 'Synthetic applicant', contact: 'synthetic@example.test' },
            documentType, documentCountry: 'CN', maskedIdentityNumber: '**************002X', requiresIdentityNumber: false,
            reviewStatus: reviewed ? 'APPROVED' : 'PENDING', processingStatus: reviewed ? 'COMPLETE' : 'FAILED',
            processingError: 'KYC_OCR_UNAVAILABLE', submittedAt: '2026-10-30T00:00:00Z', reviewedAt: reviewed ? '2026-11-01T00:00:00Z' : null,
            isSuperseded: false, currentApplicationId: id, reviewable: !reviewed, retryable: !reviewed,
        });
        const detail = id => ({ application: application(id), company: { id: tenantId, name: 'Offline synthetic company' }, canViewDocuments: allowed, canReview: true });
        const history = selected => ({
            company: detail(selected).company, user: { id: userId, displayName: 'Synthetic applicant', email: 'synthetic@example.test' },
            canViewDocuments: allowed, canReview: true,
            applications: { items: noApplications ? [] : [application(applicationId), application(newerId)], page: 1, lastPage: 1, total: noApplications ? 0 : 2 },
            application: noApplications ? null : application(selected),
        });
        const state = (url, id = applicationId) => {
            const usersPage = url.pathname === '/platform/users';
            return { component: usersPage ? 'platform/Users' : 'platform/KycDetail', url: url.pathname + url.search, version: 'fixture', props: {
                errors: {}, publicAssets: [], tenant: null, flash: {},
                auth: { admin: { id: 'offline-admin', name: 'Synthetic owner', scope: 'PLATFORM', permissions: ['users.read', 'kyc.read', 'kyc.review', ...(allowed ? ['kyc.document.view'] : [])] }, user: null },
                i18n: { locale: url.searchParams.get('fixture_locale') ?? 'en', enabledLocales: ['en', 'zh-CN'], timezone: 'Asia/Shanghai', surface: 'platform' },
                ...detail(id), ...(usersPage ? {
                    companies: [detail(id).company], filters: { company: tenantId }, canViewKyc: true, canViewFunds: false, canViewTopups: false,
                    canManageRestrictions: false, canManagePartners: false, canManageSupport: false, canCreateUser: false, canRemark: false,
                    canAdjustWallet: false, canChangeInvitation: false, canChangeReferrer: false, canAdjustCommission: false,
                    financialAccess: { balances: false, receipts: false, commission: false, withdrawals: false },
                    users: { data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
                } : {}),
            } };
        };
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            assert.equal(url.origin, origin, 'all fixtures stay on the intercepted origin');
            if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
            if (url.pathname.startsWith('/offline-preview/')) return route.fulfill({ contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="560" height="350"><rect width="560" height="350" rx="24" fill="#e2e8f0"/><text x="35" y="180" font-size="28" fill="#334155">SYNTHETIC ${url.pathname.includes('back') ? 'BACK' : 'FRONT'} FIXTURE</text></svg>` });
            if (url.pathname.startsWith('/media/images/')) { originalGets.push(url.href); return route.abort(); }
            const id = [applicationId, newerId].find(value => url.pathname.startsWith(detailPath(value)));
            if (request.method() === 'POST') {
                assert.ok(id, 'document requests must retain the explicitly selected application');
                const data = request.postDataJSON();
                assert.equal(request.headers()['x-csrf-token'], 'offline-csrf');
                if (url.pathname === detailPath(id) + '/review') {
                    reviewRequests.push({ id, data }); reviewed = true;
                    return route.fulfill({ json: { reviewed: true } });
                }
                assert.ok([detailPath(id) + '/documents', detailPath(id) + '/documents/original'].includes(url.pathname), 'no unexpected write can escape');
                documentRequests.push({ id, path: url.pathname, data });
                if (!recent && data.password !== password) return route.fulfill({ status: 422, json: { errors: { password: ['The password is incorrect.'] } } });
                recent = true;
                if (url.pathname.endsWith('/original')) {
                    assert.ok(['front', 'back'].includes(data.side));
                    const mode = originalMode;
                    if (mode === 'delayed') await new Promise(resolve => { releaseOriginal = resolve; delayedStarted?.(); });
                    if (mode === 'missing') return route.fulfill({ status: 404, json: { error: { message: 'SECRET storage object credentials and provider error' } } });
                    const result = { originalUrl: originalUrl(id, data.side), side: data.side, tenantId, applicationId: id };
                    if (mode === 'empty') result.originalUrl = '';
                    if (mode === 'wrong-side') result.side = data.side === 'front' ? 'back' : 'front';
                    if (mode === 'wrong-tenant') result.tenantId = 'another-company';
                    if (mode === 'wrong-application') result.applicationId = newerId;
                    if (mode === 'temporary') result.originalUrl += '&expires=1';
                    if (mode === 'bad-url') result.originalUrl = 'javascript:alert(1)';
                    if (mode === 'credential-url') result.originalUrl = 'https://user:password@example.test/image';
                    await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } }).catch(() => {});
                    if (mode === 'delayed') delayedFinished?.();
                    return;
                }
                if (previewMode === 'error') return route.fulfill({ status: 503, json: { error: { message: 'Unable to load. Please try again.' } } });
                const sides = documentType === 'PASSPORT' ? ['front'] : ['front', 'back'];
                return route.fulfill({ json: {
                    documents: Object.fromEntries(sides.map(side => [side, `${origin}/offline-preview/${side}.svg?x-oss-process=fixture-thumbnail`])),
                    documentSources: {},
                }, headers: { 'Cache-Control': 'private, no-store' } });
            }
            assert.equal(request.method(), 'GET');
            if (url.pathname === userPath) return route.fulfill({ json: history(url.searchParams.get('application') || applicationId) });
            if (id && request.headers()['x-requested-with'] === 'XMLHttpRequest' && !request.headers()['x-inertia']) return route.fulfill({ json: detail(id) });
            if (url.pathname === '/platform/users' || id) {
                const dto = state(url, id);
                if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: dto });
                return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="offline-csrf">${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(dto)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
            }
            return route.fulfill({ status: 404, json: {} });
        });
        const front = page.locator('[data-kyc-document-side="front"]');
        const back = page.locator('[data-kyc-document-side="back"]');
        const copy = side => side.getByRole('button', { name: 'Copy original link', exact: true });
        const clipboard = () => page.evaluate(() => [...window.__originalClipboard]);
        const acknowledge = async () => {
            const result = page.locator('[data-operation-result]');
            if (await result.isVisible()) await result.getByRole('button').click();
        };
        const copyFailure = 'Unable to obtain the original image link. Please retry.';
        const passwordFailure = 'Confirm your current administrator password and try again.';

        await page.goto(origin + detailPath(applicationId));
        await copy(front).waitFor(); await copy(back).waitFor();
        assert.deepEqual(documentRequests, [], 'ordinary KYC reads never obtain document links');
        assert.equal(await page.getByRole('textbox', { name: 'Original image link', exact: true }).count(), 0);
        await copy(front).click(); await front.getByText(passwordFailure, { exact: true }).waitFor();
        assert.deepEqual(documentRequests.at(-1).data, { side: 'front', password: '' });
        const passwordInput = page.getByLabel('Current password', { exact: true });
        await passwordInput.fill('wrong-fixture-password'); await copy(front).click();
        await front.getByText(passwordFailure, { exact: true }).waitFor();
        assert.deepEqual(await clipboard(), []); assert.equal(await passwordInput.inputValue(), '');
        await passwordInput.fill(password); await copy(front).click();
        await front.getByText('Original link copied.', { exact: true }).waitFor();
        assert.deepEqual(await clipboard(), [originalUrl(applicationId, 'front')]);
        assert.equal(await passwordInput.inputValue(), '');
        await copy(back).click(); await back.getByText('Original link copied.', { exact: true }).waitFor();
        assert.deepEqual(await clipboard(), [originalUrl(applicationId, 'front'), originalUrl(applicationId, 'back')]);
        assert.deepEqual(documentRequests.at(-1).data, { side: 'back', password: '' }, 'recent confirmation is reused without retaining the password');
        for (const value of await clipboard()) {
            const url = new URL(value); assert.equal(url.searchParams.get('profile'), 'original');
            for (const key of ['expires', 'viewer', 'x-oss-process']) assert.equal(url.searchParams.has(key), false);
        }
        assert.equal(page.url(), origin + detailPath(applicationId));
        assert.equal(await page.locator('a[href*="signature="]').count(), 0, 'capability URLs never appear as automatic navigation');

        const originalsBeforePreview = documentRequests.filter(request => request.path.endsWith('/original')).length;
        await page.getByRole('button', { name: 'View photos', exact: true }).click();
        await front.getByRole('img').waitFor(); await back.getByRole('img').waitFor();
        assert.equal(documentRequests.filter(request => request.path.endsWith('/original')).length, originalsBeforePreview, 'previewing photos never obtains permanent links');
        assert.ok((await front.getByRole('img').getAttribute('src')).includes('x-oss-process=fixture-thumbnail'));
        assert.ok((await back.getByRole('img').getAttribute('src')).includes('/back.svg'));
        const readsBeforeExpiry = documentRequests.length;
        recent = false; await copy(back).click(); await back.getByText(passwordFailure, { exact: true }).waitFor();
        assert.equal(documentRequests.length, readsBeforeExpiry + 1); assert.equal(await back.getByRole('img').count(), 1);
        await passwordInput.fill(password); await copy(back).click(); await back.getByText('Original link copied.', { exact: true }).waitFor();
        for (const mode of ['missing', 'empty', 'wrong-side', 'wrong-tenant', 'wrong-application', 'temporary', 'bad-url', 'credential-url']) {
            originalMode = mode; const before = (await clipboard()).length;
            await copy(front).click(); await front.getByText(copyFailure, { exact: true }).waitFor();
            assert.equal((await clipboard()).length, before, `${mode} evidence cannot be copied`);
            assert.equal(await front.getByRole('img').count(), 1, 'copy errors retain existing document previews');
            assert.equal(await page.getByRole('textbox', { name: 'Original image link', exact: true }).count(), 0);
            assert.ok(!(await page.locator('body').innerText()).includes('SECRET'));
        }
        originalMode = 'normal';
        await page.evaluate(() => { window.__originalClipboardFail = true; });
        await copy(front).click();
        const fallback = front.getByRole('textbox', { name: 'Original image link', exact: true });
        await fallback.waitFor(); assert.equal(await fallback.inputValue(), originalUrl(applicationId, 'front'));
        assert.ok(await fallback.getAttribute('readonly') !== null);
        await fallback.focus();
        assert.deepEqual(await fallback.evaluate(element => [element.selectionStart, element.selectionEnd]), [0, originalUrl(applicationId, 'front').length]);
        assert.equal(await back.getByRole('textbox', { name: 'Original image link', exact: true }).count(), 0, 'fallback remains scoped to the requested side');
        assert.ok(!(await page.locator('[data-operation-result]').count()), 'copy never includes the URL in global notifications');
        assert.deepEqual(await page.evaluate(() => [Object.values(localStorage), Object.values(sessionStorage)].flat().filter(value => value.includes('signature='))), []);
        await page.screenshot({ path: `${out}/${name}-original-fallback-en.png`, fullPage: true });

        await page.evaluate(() => { window.__originalClipboardFail = false; });
        await page.getByRole('button', { name: 'Approve', exact: true }).click();
        await page.locator('[data-operation-result]').waitFor(); await acknowledge();
        await page.getByRole('button', { name: 'Approve', exact: true }).waitFor({ state: 'hidden' });
        assert.deepEqual(reviewRequests, [{ id: applicationId, data: { decision: 'approve' } }], 'manual review still targets the opened application');
        reviewed = false;

        // Existing preview confirmation also authorizes a later explicit original read.
        recent = false; await page.reload(); await passwordInput.fill(password);
        await page.getByRole('button', { name: 'View photos', exact: true }).click();
        await front.getByRole('img').waitFor(); await copy(back).click();
        await back.getByText('Original link copied.', { exact: true }).waitFor();
        assert.deepEqual(documentRequests.at(-1).data, { side: 'back', password: '' });

        // A failed preview cannot prevent independently obtaining an original link.
        previewMode = 'error'; await page.reload();
        await page.getByRole('button', { name: 'View photos', exact: true }).click();
        await page.locator('[data-operation-result]').waitFor(); await acknowledge();
        await copy(front).click(); await front.getByText('Original link copied.', { exact: true }).waitFor();
        previewMode = 'normal';

        // Switching scoped history invalidates an in-flight original request.
        await page.goto(origin + historyPath);
        await copy(front).waitFor(); originalMode = 'delayed';
        const started = new Promise(resolve => { delayedStarted = resolve; });
        const finished = new Promise(resolve => { delayedFinished = resolve; });
        await copy(front).click(); await started;
        await page.getByRole('combobox', { name: 'Verification history', exact: true }).selectOption(newerId);
        await copy(front).waitFor();
        const beforeStale = await clipboard(); releaseOriginal(); await finished;
        await page.waitForTimeout(50);
        assert.deepEqual(await clipboard(), beforeStale, 'a response for the old application never writes to the clipboard');
        assert.equal(await page.getByRole('textbox', { name: 'Original image link', exact: true }).count(), 0);
        originalMode = 'normal'; await copy(back).click(); await back.getByText('Original link copied.', { exact: true }).waitFor();
        assert.equal(documentRequests.at(-1).id, newerId); assert.equal((await clipboard()).at(-1), originalUrl(newerId, 'back'));

        noApplications = true; await page.goto(origin + historyPath);
        await page.getByText('No identity verification submitted yet.', { exact: true }).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Copy original link', exact: true }).count(), 0);
        noApplications = false; allowed = false;
        const beforeDenied = documentRequests.length; await page.goto(origin + detailPath(applicationId));
        await page.getByRole('button', { name: 'Refresh status', exact: true }).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Copy original link', exact: true }).count(), 0);
        assert.equal(await page.getByLabel('Current password', { exact: true }).count(), 0);
        assert.equal(documentRequests.length, beforeDenied);

        allowed = true; documentType = 'PASSPORT';
        await page.goto(origin + detailPath(applicationId)); await copy(front).waitFor();
        assert.equal(await back.count(), 0, 'passport has only its information-page original');
        await copy(front).click(); await front.getByText('Original link copied.', { exact: true }).waitFor();
        documentType = 'NATIONAL_ID';
        await page.goto(origin + detailPath(applicationId) + '?fixture_locale=zh-CN');
        await front.getByRole('button', { name: '复制原图链接', exact: true }).waitFor();
        await back.getByRole('button', { name: '复制原图链接', exact: true }).waitFor();
        await page.evaluate(() => { window.__originalClipboardFail = true; });
        await back.getByRole('button', { name: '复制原图链接', exact: true }).click();
        const chineseFallback = back.getByRole('textbox', { name: '原图链接', exact: true });
        await chineseFallback.waitFor(); assert.equal(await chineseFallback.inputValue(), originalUrl(applicationId, 'back'));
        await page.setViewportSize({ width: 375, height: 852 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'the original-link fallback fits a narrow page');
        await page.screenshot({ path: `${out}/${name}-original-fallback-zh-375.png`, fullPage: true });
        assert.deepEqual(originalGets, [], 'copy does not automatically load or navigate to original media');
        assert.deepEqual(errors, []);
        console.log(`${name}: permanent front/back originals, recent-password reuse/expiry, scope/error/empty/stale gates, selected fallback, independent preview/review, permissions/passport/history and en/zh narrow layouts passed (fully offline).`);
        await context.close();
    } finally { await browser.close(); }
}
