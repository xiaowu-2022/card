import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const root = resolve('dist/clients/specpay/release/h5');
for (const engine of [chromium, webkit]) {
    const browser = await engine.launch({ headless: true, ...(engine === chromium ? { channel: 'chrome' } : {}) });
    try {
        for (const role of [false, true, undefined]) {
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const page = await context.newPage();
            await context.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/api/v1')) {
                    const key = url.pathname.slice(7);
                    const counts = { messages: 1, support: 2, agentSupport: 9, supportAgent: role };
                    if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, supportAgent: role, unread: counts, locale: 'en' } });
                    if (key === '/unread') return route.fulfill({ json: counts });
                    // A stale account response must not grant access on its own.
                    if (key === '/account') return route.fulfill({ json: { supportAgent: true, kycStatus: 'APPROVED', promotionRank: 0, accountQualified: true } });
                    return route.fulfill({ json: {} });
                }
                if (url.origin !== 'http://support.test') return route.abort();
                const file = resolve(root, url.pathname === '/' ? 'index.html' : url.pathname.slice(1));
                if (!file.startsWith(root + '/') || !existsSync(file)) return route.abort();
                return route.fulfill({ body: readFileSync(file), contentType: { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }[extname(file)] });
            });
            await page.goto('http://support.test/#/pages/account/index');
            await page.locator('.menu-item').filter({ hasText: 'Customer support' }).waitFor();
            assert.equal(await page.locator('.menu-item').filter({ hasText: 'Support workspace' }).count(), role === true ? 1 : 0);
            await context.close();
        }
        console.log(`${engine.name()}: agent-only entry, ordinary user and missing-role cases passed`);
    } finally { await browser.close(); }
}
