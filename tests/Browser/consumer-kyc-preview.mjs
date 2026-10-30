import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { extname } from 'node:path';
import { chromium } from 'playwright';

// Fully intercepted fixtures: no API, OCR, storage or provider service is contacted.
const fixtures = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8'));
const root = process.env.CONSUMER_H5_TEST_ROOT ?? 'mobile/uni-app/dist/build/h5';
const origin = process.env.KYC_PREVIEW_ORIGIN ?? 'http://127.0.0.1:5217';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const frontId = '11111111-1111-4111-8111-111111111111';
const backId = '22222222-2222-4222-8222-222222222222';
const calls = [], errors = [];
const failureCopy = {
    KYC_BACK_VALIDITY_UNRECOGNIZED: 'The ID card validity period could not be recognized or validated. Please upload a clear, complete photo of the back.',
    KYC_DOCUMENT_EXPIRED: 'The ID card has expired. Please upload a valid identity document.',
    KYC_DOCUMENT_NOT_YET_VALID: 'The ID card is not yet valid. Please upload an identity document that is currently valid.',
};
mkdirSync('output/kyc-back-validity', { recursive: true });
try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await context.addInitScript(() => localStorage.setItem('consumer-bearer-v1', '12|' + 'A'.repeat(64)));
    const page = await context.newPage();
    const dto = structuredClone(fixtures.pages['/kyc']);
    Object.assign(dto.props, { canSubmit: true, canReverify: false });
    Object.assign(dto.props.kyc, { status: 'NOT_SUBMITTED', processingStatus: null, processingError: null });
    const boot = { ...structuredClone(fixtures.authenticated), locale: 'en', csrfToken: null, publicAssets: {}, unread: null };
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', async route => {
        const request = route.request(), u = new URL(request.url());
        if (u.protocol === 'blob:' || u.protocol === 'data:') return route.continue();
        if (u.origin !== origin) return route.abort();
        if (!u.pathname.startsWith('/api/')) {
            const file = root + (u.pathname === '/' ? '/index.html' : u.pathname);
            const types = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg' };
            try { return route.fulfill({ body: readFileSync(file), contentType: types[extname(file)] ?? 'text/html' }); }
            catch { return route.abort(); }
        }
        assert.ok(u.pathname.startsWith('/api/mobile/v1/'));
        assert.equal(request.headers().cookie, undefined);
        const path = u.pathname.slice('/api/mobile/v1'.length);
        if (request.method() === 'GET') {
            if (path === '/bootstrap') return route.fulfill({ json: boot });
            if (path === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (path === '/client/kyc') return route.fulfill({ json: dto });
            return route.fulfill({ json: {} });
        }
        const data = request.headers()['content-type']?.includes('multipart/form-data') ? {} : request.postDataJSON();
        calls.push({ path, data });
        if (path === '/images/direct') return route.fulfill({ json: { id: data.field === 'front' ? frontId : backId, mode: 'server', fields: {} } });
        if (/^\/images\/direct\/.+\/(backup|complete)$/.test(path)) return route.fulfill({ status: 204 });
        assert.notEqual(path, '/client/kyc/recognize-front', 'photo selection does not run client-side OCR');
        if (path === '/client/kyc/applications') {
            Object.assign(dto.props, { canSubmit: false });
            Object.assign(dto.props.kyc, { status: 'PENDING', processingStatus: 'QUEUED', processingError: null });
            return route.fulfill({ status: 202, json: { applicationId: 'offline-application', processingStatus: 'QUEUED', success: 'Your identity documents were submitted for review.' } });
        }
        return route.fulfill({ json: {} });
    });
    await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
    await page.getByText('Submit identity documents', { exact: true }).waitFor();
    const submit = page.locator('.submit-button');
    assert.ok(await submit.getAttribute('disabled') !== null);
    const select = async label => {
        const chooser = page.waitForEvent('filechooser');
        await page.locator('.upload-field').filter({ has: page.getByText(label, { exact: true }) }).locator('.upload-control').click();
        await (await chooser).setFiles({ name: 'synthetic.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAADklEQVQImWP4DwYMEAoAU7oL9W/sIDEAAAAASUVORK5CYII=', 'base64') });
    };
    await select('ID front');
    assert.ok(await submit.getAttribute('disabled') !== null);
    await select('ID back');
    assert.equal(calls.filter(c => c.path.startsWith('/images/direct') || c.path.startsWith('/client/kyc/')).length,
        0, 'both photos stay local until submission');
    await submit.click();
    await page.getByText('Documents received. Processing continues in the background; you will be notified of the result.', { exact: true }).waitFor();
    const submitted = calls.find(c => c.path === '/client/kyc/applications');
    assert.ok(submitted);
    assert.equal(submitted.data.front_upload_id, frontId);
    assert.equal(submitted.data.back_upload_id, backId);
    assert.equal(Object.hasOwn(submitted.data, 'identity_number'), false);
    assert.equal(calls.filter(c => c.path.includes('recognize')).length, 0);
    await page.screenshot({ path: 'output/kyc-back-validity/queued.png', fullPage: true });
    for (const [processingError, copy] of Object.entries(failureCopy)) {
        Object.assign(dto.props, { canSubmit: true });
        Object.assign(dto.props.kyc, { status: 'RESUBMISSION_REQUIRED', processingStatus: 'FAILED', processingError });
        await page.reload();
        await page.getByText(copy, { exact: true }).waitFor();
        await page.getByText('Resubmit documents', { exact: true }).waitFor();
        assert.equal(await page.locator('.upload-control').count(), 2);
    }
    // Expired failures do not occupy the new submission slot.
    Object.assign(dto.props.kyc, { processingError: 'KYC_DOCUMENT_EXPIRED' });
    await page.reload();
    await page.getByText(failureCopy.KYC_DOCUMENT_EXPIRED, { exact: true }).waitFor();
    await select('ID front');
    await select('ID back');
    await submit.click();
    await page.getByText('Documents received. Processing continues in the background; you will be notified of the result.', { exact: true }).waitFor();
    assert.equal(calls.filter(c => c.path === '/client/kyc/applications').length, 2);
    assert.deepEqual(errors, []);
    console.log('PASS async two-sided submission, safe validity failures and immediate resubmission with offline bearer fixtures');
} finally {
    await browser.close();
}
