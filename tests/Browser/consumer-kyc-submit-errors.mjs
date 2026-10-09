import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5231';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
mkdirSync('artifacts/uni-parity/kyc-submit-errors', { recursive: true });
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 780 }, isMobile: true });
        const page = await context.newPage();
        const errors = [], submissions = [];
        let mode = 'size';
        const dto = structuredClone(fixture.pages['/kyc?fixture=verified']);
        Object.assign(dto.props, { canReverify: true, canSubmit: false });
        Object.assign(dto.props.kyc, { status: 'APPROVED', processingStatus: 'COMPLETE', documentType: 'NATIONAL_ID', documentCountry: 'CN' });
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', route => {
            const req = route.request(), u = new URL(req.url());
            if (u.pathname.startsWith('/api/v1')) {
                const key = u.pathname.slice(7);
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'zh-CN' } });
                if (key === '/client/kyc') return route.fulfill({ json: dto });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                if (key === '/images/direct') return route.fulfill({ json: { id: req.postDataJSON().field, mode: 'server', fields: {} } });
                if (key.endsWith('/backup')) return mode === 'size' && key.includes('/back/')
                    ? route.fulfill({ status: 413, contentType: 'text/html', body: '<h1>SECRET proxy body</h1>' })
                    : route.fulfill({ status: 204 });
                if (key.endsWith('/complete')) return route.fulfill({ status: 204 });
                if (key === '/client/kyc/applications') {
                    submissions.push(req.postDataJSON());
                    return route.fulfill({ status: 504, contentType: 'text/html', body: '<h1>SECRET timeout</h1>' });
                }
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (u.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
        for (mode of ['size', 'timeout']) {
            if (mode === 'timeout') await page.reload();
            await page.getByText('重新认证', { exact: true }).click();
            for (const control of await page.locator('.upload-control').all()) {
                const chooser = page.waitForEvent('filechooser');
                await control.click();
                await (await chooser).setFiles({ name: 'test.png', mimeType: 'image/png', buffer: png });
            }
            await page.locator('.submit-button').click();
            const alert = page.locator('.kyc-card .form-errors');
            await alert.waitFor();
            const text = await alert.innerText();
            assert.ok(!text.includes('SECRET') && !text.includes('加载失败'));
            if (mode === 'size') {
                assert.match(text, /背面上传失败/);
                assert.match(text, /超过服务器允许的上传大小/);
                assert.equal(submissions.length, 0);
            } else {
                assert.match(text, /提交结果/);
                assert.match(text, /请求超时/);
                assert.match(text, /先刷新状态/);
                assert.equal(submissions.length, 1);
            }
            assert.equal(await page.locator('.upload-preview').count(), 2, 'keep photos for user correction');
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.screenshot({ path: `artifacts/uni-parity/kyc-submit-errors/${name}-${mode}.png` });
            await page.locator('.kyc-card > uni-button.secondary').click();
            await page.getByText('重新认证', { exact: true }).click();
            assert.equal(await page.locator('.form-errors').count(), 0, 'new attempt has no stale error');
        }
        assert.deepEqual(errors, []);
        console.log(`PASS ${name}: document side, upload limit, uncertain submission, draft and reset`);
    } finally { await browser.close(); }
}
