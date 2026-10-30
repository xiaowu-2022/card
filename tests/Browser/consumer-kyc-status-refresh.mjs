import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname } from 'node:path';
import { chromium, webkit } from 'playwright';

// Serve only the actual local release and intercepted synthetic DTOs. No real service calls.
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const root = process.env.CONSUMER_H5_TEST_ROOT ?? 'public/h5';
const origin = 'http://127.0.0.1:5239';
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 780 } });
        await context.addInitScript(() => localStorage.setItem('consumer-bearer-v1', '12|' + 'A'.repeat(64)));
        const page = await context.newPage();
        await page.clock.install();
        const errors = [], calls = [];
        let failRead = false;
        const dto = structuredClone(fixture.pages['/kyc']);
        Object.assign(dto.props, { canSubmit: false, canReverify: false });
        Object.assign(dto.props.kyc, { status: 'PENDING', processingStatus: 'PROCESSING', processingError: null });
        page.on('pageerror', error => errors.push(error.message));
        await context.route('**/*', route => {
            const req = route.request(), u = new URL(req.url());
            if (u.protocol === 'blob:' || u.protocol === 'data:') return route.continue();
            if (u.origin !== origin) return route.abort();
            if (u.pathname.startsWith('/api/')) {
                assert.equal(req.headers().cookie, undefined);
                const key = u.pathname.slice('/api/mobile/v1'.length);
                calls.push({ key, method: req.method() });
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (key === '/client/kyc') return failRead ? route.fulfill({ status: 503, json: {} }) : route.fulfill({ json: dto });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                if (key === '/session') return route.fulfill({ json: { tenant: fixture.authenticated.tenant, user: fixture.authenticated.user, restricted: false } });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (req.method() !== 'GET') return route.abort();
            const file = root + (u.pathname === '/' ? '/index.html' : u.pathname);
            try { return route.fulfill({ body: readFileSync(file), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png' })[extname(file)] ?? 'text/html' }); }
            catch { return route.abort(); }
        });
        const reads = () => calls.filter(call => call.key === '/client/kyc').length;
        const settle = async expected => { await page.waitForFunction(() => true); for (let i = 0; i < 100 && reads() < expected; i++) await new Promise(resolve => setTimeout(resolve, 10)); assert.equal(reads(), expected); };
        await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
        await page.getByText('Documents received. Processing continues in the background; you will be notified of the result.', { exact: true }).waitFor();
        assert.equal(reads(), 1);
        // Mark the mounted instance; changing status must preserve this actual DOM node.
        await page.locator('.status-banner').evaluate(node => node.dataset.offlineMounted = 'kept');
        failRead = true;
        await page.clock.fastForward(10000); await settle(2);
        assert.equal(await page.locator('[data-offline-mounted="kept"]').count(), 1);
        assert.equal(await page.getByRole('alertdialog').count(), 0, 'quiet failure cannot open network recovery');
        failRead = false;
        await page.evaluate(() => { window.__offlineHidden = true; Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.__offlineHidden }); Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => window.__offlineHidden ? 'hidden' : 'visible' }); document.dispatchEvent(new Event('visibilitychange')); });
        await page.clock.fastForward(60000); assert.equal(reads(), 2);
        await page.evaluate(() => { window.__offlineHidden = false; document.dispatchEvent(new Event('visibilitychange')); document.dispatchEvent(new Event('visibilitychange')); });
        await settle(3);
        Object.assign(dto.props.kyc, { status: 'APPROVED', processingStatus: null });
        await page.clock.fastForward(10000); await settle(4);
        await page.getByText('Identity verified', { exact: true }).waitFor();
        assert.equal(await page.locator('[data-offline-mounted="kept"]').count(), 1);
        await page.clock.fastForward(60000); assert.equal(reads(), 4, 'terminal review stops status reads');
        assert.equal(calls.filter(call => call.key === '/bootstrap').length, 1, 'polling does not bootstrap repeatedly');
        assert.equal(calls.filter(call => call.key.includes('kyc') && call.method !== 'GET').length, 0, 'status reads never replay submission');
        assert.deepEqual(errors, []);
        console.log(`PASS ${name}: pending automatic status, quiet failure, hidden pause, foreground dedup, mounted page and terminal stop`);
    } finally { await browser.close(); }
}
