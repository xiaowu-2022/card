import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';

const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5229';
const data = balance => {
    const result = structuredClone(fixture.pages['/cards?fixture=cards']);
    result.props.cards[0].balance = balance;
    return result;
};
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
        const page = await context.newPage(), errors = [], mutations = [], waiting = [];
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', route => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                if (req.method() !== 'GET') { mutations.push(url.pathname); return route.fulfill({ json: {} }); }
                const key = url.pathname.slice(7);
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                if (key === '/client/cards') { waiting.push(route); return; }
                if (key.startsWith('/client/')) return route.fulfill({ json: fixture.pages[key.slice(7)] ?? {} });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            // Keep the test entirely offline, including preview proxy paths.
            if (url.origin !== origin || /^\/(images|storage)\//.test(url.pathname) || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        const pending = async () => {
            const deadline = Date.now() + 10000;
            while (!waiting.length && Date.now() < deadline) await page.waitForTimeout(20);
            assert.ok(waiting.length, 'cards request started');
            return waiting.shift();
        };
        const balance = page.locator('.cards-page .card-group .balance').first();
        const showsBalance = async value => {
            await page.waitForFunction(value => document.querySelector('.cards-page .card-group .balance')?.textContent.includes(value), value);
        };
        const switchBack = async () => {
            await page.locator('.tabs .tab').filter({ hasText: /^Me$/ }).click();
            await page.waitForURL(/pages\/account\/index/);
            await page.locator('.tabs .tab').filter({ hasText: /^Cards$/ }).click();
            await page.waitForURL(/pages\/cards\/index/);
            return pending();
        };
        await page.goto(origin + '/#/pages/cards/index');
        let request = await pending();
        assert.equal(await balance.count(), 0, 'first visit has no preview');
        await page.locator('.page-skeleton').first().waitFor();
        await request.fulfill({ json: data('12.34') }); await showsBalance('12.34');

        request = await switchBack();
        await showsBalance('12.34');
        assert.match(await page.locator('.refresh-status').innerText(), /Refreshing/);
        await request.fulfill({ json: data('56.78') }); await showsBalance('56.78');

        request = await switchBack();
        await request.fulfill({ status: 503, json: { message: 'Unavailable' } });
        await page.locator('.refresh-status').filter({ hasText: 'Refresh failed' }).waitFor();
        await showsBalance('56.78');
        await page.locator('.refresh-status').getByText('Retry', { exact: true }).click();
        request = await pending();
        await request.fulfill({ json: data('90.12') }); await showsBalance('90.12');

        // A request from an unmounted page must not overwrite a newer preview.
        const old = await switchBack();
        request = await switchBack();
        await request.fulfill({ json: data('34.56') }); await showsBalance('34.56');
        await old.fulfill({ json: data('11.11') });
        request = await switchBack(); await showsBalance('34.56');

        await request.fulfill({ status: 403, json: { message: 'Forbidden' } });
        await page.locator('.empty').filter({ hasText: 'Unable to load' }).waitFor();
        assert.equal(await balance.count(), 0, 'denied authorization removes preview');
        request = await switchBack();
        assert.equal(await balance.count(), 0, 'denied preview cannot be reused');
        await request.fulfill({ json: data('78.90') }); await showsBalance('78.9');
        request = await switchBack();
        await request.fulfill({ status: 401, json: { message: 'Unauthenticated' } });
        await page.waitForURL(/pages\/login\/index/);
        assert.equal(await balance.count(), 0);
        assert.deepEqual(errors, []);
        assert.ok(mutations.every(path => ['/api/v1/wallet/ensure', '/api/v1/presence'].includes(path)), JSON.stringify(mutations));
        await context.close();
        console.log(`PASS ${name}: initial skeleton, instant preview, background updates, retry, stale response and authorization clearing`);
    } finally { await browser.close(); }
}
