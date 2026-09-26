import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const output = '/tmp/card-uni-ui';
await mkdir(output, { recursive: true });
try {
    for (const locale of ['zh-CN', 'en', 'ms', 'es']) {
        for (const width of [375, 768, 1440]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = []; page.on('pageerror', (error) => errors.push(error.message));
            let reads = 0; let count = 120; let support = 3;
            const id = '11111111-1111-4111-8111-111111111111';
            const title = 'A long notification title for responsive layout ' .repeat(3);
            const message = { id, kind: 'PLATFORM', template: null, parameters: {}, title, body: '<img src=x onerror=alert(1)>\nPlain text announcement', time: '2026-09-26T03:00:00Z', href: null, readAt: null };
            await page.route('**/api/v1/**', async (route) => {
                const url = new URL(route.request().url()); const path = url.pathname.slice('/api/v1'.length);
                let body = {};
                if (path === '/bootstrap') body = { apiVersion: 1, tenant: { id: 'test', slug: 'tenant-a', name: 'Spec Pay', logoUrl: null, primaryColor: '#39ad8d' }, user: { id: 'test', accountId: '202600000001', displayName: 'Layout test', email: 'test@example.test' }, restricted: false, locale, locales: ['zh-CN','en','ms','es'], timezone: 'Asia/Kuala_Lumpur', csrfToken: 'test-csrf', unread: { messages: count, support } };
                else if (path === '/account') body = { kycStatus: 'APPROVED', promotionRank: 0, accountQualified: true };
                else if (path === '/unread') body = { messages: count, support };
                else if (path === '/messages') body = { items: count ? [message] : [], page: 1, hasMore: false };
                else if (path === `/messages/${id}`) body = message;
                else if (path === `/messages/${id}/read` || path === '/messages/read-all') { assert.equal(route.request().method(), 'POST'); reads++; count = 0; }
                else if (path === '/support') body = { messages: [{ id, sequence: 4, fromSupport: true, text: 'Support reply', createdAt: message.time, imageUrl: null }], olderCursor: null };
                else if (path === '/support/read') { assert.equal(JSON.parse(route.request().postData()).through, 4); support = 0; }
                else if (path === '/cards') body = { cards: [] };
                else if (path === '/assets') body = { estimate: '100.12345678', assets: [{ asset: 'ETH', available: '0.000000000000000001', held: '0', deposit: '0' }] };
                else { await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' }); return; }
                await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
            });
            await page.goto('http://127.0.0.1:5200/#/pages/account/index');
            await page.getByText('202600000001', { exact: false }).waitFor();
            assert.ok(await page.getByText('99+', { exact: true }).count() >= 2);
            await page.screenshot({ path: `${output}/account-${locale}-${width}.png`, fullPage: true });
            await page.goto('http://127.0.0.1:5200/#/pages/messages/index');
            await page.getByText(title.trim(), { exact: false }).first().waitFor();
            assert.equal(reads, 0);
            await page.screenshot({ path: `${output}/messages-${locale}-${width}.png`, fullPage: true });
            const afterRead = page.waitForResponse((r) => r.url().endsWith('/unread'));
            await page.locator('[role="button"]').filter({ hasText: title.trim() }).first().click();
            await page.waitForFunction(() => location.hash.includes('/pages/messages/detail'));
            await afterRead;
            assert.equal(reads, 1);
            assert.equal(await page.locator('img[src="x"]').count(), 0);
            const afterSupport = page.waitForResponse((r) => r.url().endsWith('/support/read'));
            await page.goto('http://127.0.0.1:5200/#/pages/support/index');
            await page.getByText('Support reply', { exact: true }).waitFor();
            await afterSupport;
            assert.equal(support, 0);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            assert.equal(overflow, false, `${locale}/${width}: overflow`);
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`PASS ${locale} ${width}px`);
        }
    }
} finally { await browser.close(); }
