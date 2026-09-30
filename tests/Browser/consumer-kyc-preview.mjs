import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const fixtures = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8'));
const origin = process.env.KYC_PREVIEW_ORIGIN ?? 'http://127.0.0.1:5217';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const frontId = '11111111-1111-4111-8111-111111111111';
const backId = '22222222-2222-4222-8222-222222222222';
const number = '11010519491231002X';
const calls = [], errors = [];
mkdirSync('artifacts/kyc-preview', { recursive: true });
try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const dto = structuredClone(fixtures.pages['/kyc']);
    dto.props.i18n.locale = 'en';
    dto.props.canSubmit = true;
    dto.props.kyc.status = 'NOT_SUBMITTED';
    const boot = structuredClone(fixtures.authenticated);
    boot.locale = 'en';
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', async route => {
        const request = route.request(), u = new URL(request.url());
        if (u.origin !== origin) return route.abort();
        if (!u.pathname.startsWith('/api/v1/')) return route.continue();
        const path = u.pathname.slice('/api/v1'.length);
        if (request.method() === 'GET') {
            if (path === '/bootstrap') return route.fulfill({ json: boot });
            if (path === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (path === '/client/kyc') return route.fulfill({ json: dto });
            return route.fulfill({ json: {} });
        }
        const data = request.headers()['content-type']?.includes('multipart/form-data') ? {} : request.postDataJSON();
        calls.push({ path, data });
        if (path === '/images/direct') return route.fulfill({ json: { id: data.field === 'front' ? frontId : backId, mode: 'server' } });
        if (/^\/images\/direct\/.+\/(backup|complete)$/.test(path)) return route.fulfill({ status: 204 });
        if (path === '/client/kyc/recognize-front') return route.fulfill({ json: { identityNumber: number, frontUploadId: frontId, expiresAt: new Date(Date.now() + 600000).toISOString() } });
        if (path === '/client/kyc/applications') return route.fulfill({ status: 503, json: { error: { message: 'Document recognition is temporarily unavailable. Please try again later.' } } });
        // Authentication bootstrap may ensure the default wallet; no real writes occur.
        return route.fulfill({ json: {} });
    });
    await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
    await page.getByText('Submit identity documents', { exact: true }).waitFor();
    const submit = page.locator('uni-button').filter({ hasText: /^Submit for review$/ });
    assert.ok(await submit.getAttribute('disabled') !== null);
    const select = async label => {
        const chooser = page.waitForEvent('filechooser');
        await page.locator('.upload-field').filter({ has: page.getByText(label, { exact: true }) }).locator('uni-button').click();
        await (await chooser).setFiles({ name: 'synthetic.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') });
    };
    await select('ID front');
    await page.getByText(number, { exact: true }).waitFor();
    assert.equal(await page.locator('.recognized-number input').count(), 0);
    assert.equal(calls.filter(c => c.path === '/client/kyc/recognize-front').length, 1);
    await select('ID back');
    assert.equal(calls.filter(c => c.path === '/client/kyc/recognize-front').length, 1);
    await page.screenshot({ path: 'artifacts/kyc-preview/recognized.png', fullPage: true });
    await submit.click();
    await page.getByText('Please select and recognize the front image again.', { exact: true }).waitFor();
    const submitted = calls.find(c => c.path === '/client/kyc/applications');
    assert.ok(submitted);
    assert.equal(submitted.data.front_upload_id, frontId);
    assert.equal(submitted.data.back_upload_id, backId);
    assert.equal(Object.hasOwn(submitted.data, 'identity_number'), false);
    assert.equal(calls.filter(c => c.path === '/client/kyc/recognize-front').length, 1);
    assert.equal(calls.filter(c => c.path === '/images/direct' && c.data.field === 'front').length, 1);
    assert.deepEqual(errors, []);
    await page.screenshot({ path: 'artifacts/kyc-preview/retry.png', fullPage: true });
    console.log('PASS front preview, read-only number, no back OCR, reference submission and retry invalidation');
} finally {
    await browser.close();
}
