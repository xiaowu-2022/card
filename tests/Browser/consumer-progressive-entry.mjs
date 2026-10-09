import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';

const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const output = 'artifacts/uni-parity/progressive-entry';
mkdirSync(output, { recursive: true });
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5237';
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
        const page = await context.newPage(), errors = [], writes = [], pending = new Map(), scripts = [];
        let recovering = false;
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', route => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                const key = url.pathname.slice(7);
                if (req.method() !== 'GET') { writes.push(key); return route.fulfill({ json: {} }); }
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (['/account', '/client/dashboard', '/client/account/settings'].includes(key)) { pending.set(key, route); return; }
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (url.origin !== origin || /^\/(images|storage)\//.test(url.pathname) || req.method() !== 'GET') return route.abort();
            if (url.pathname.endsWith('.js')) scripts.push(url.pathname);
            if (/screens-Settings\..*\.js$/.test(url.pathname)) {
                // Vite may also preload the ordinary URL during recovery. Hold only the
                // actual recovery import, so the preload cannot consume our test latch.
                if (recovering && !url.searchParams.has('retry')) return route.continue();
                pending.set('settings-chunk', route); return;
            }
            return route.continue();
        });
        async function waiting(key) {
            const deadline = Date.now() + 10000;
            while (!pending.has(key) && Date.now() < deadline) await page.waitForTimeout(20);
            assert.ok(pending.has(key), key + ' started');
            const route = pending.get(key); pending.delete(key); return route;
        }
        await page.goto(origin + '/#/pages/account/index');
        const account = await waiting('/account');
        await page.locator('.menu-grid').waitFor();
        assert.equal(writes.includes('/wallet/ensure'), false, 'profile does not wait for wallet');
        assert.match(await page.locator('.verification-status').innerText(), /Loading/);
        assert.doesNotMatch(await page.locator('.level').innerText(), /inactive/);
        await page.screenshot({ path: `${output}/${name}-account-loading.png` });
        await account.fulfill({ json: { ...fixture.api['/account'], kycStatus: 'APPROVED', accountQualified: true, promotionRank: 0 } });
        await page.waitForFunction(() => document.querySelector('.verification-status')?.textContent.includes('Verified'));

        await page.locator('.tabs .tab').filter({ hasText: /^Assets$/ }).click();
        const assets = await waiting('/client/dashboard');
        await page.locator('.asset-skeleton').waitFor();
        assert.match(await page.locator('.asset-skeleton').innerText(), /Estimated total assets/);
        assert.equal(await page.locator('.asset-skeleton .action[disabled], .asset-skeleton .action[aria-disabled="true"], .asset-skeleton .action.uni-button-disabled').count(), 4);
        await page.screenshot({ path: `${output}/${name}-assets-loading.png` });
        await assets.fulfill({ status: 503, json: {} });
        await page.getByText('Try again', { exact: true }).waitFor();
        await page.getByText('Try again', { exact: true }).click();
        await (await waiting('/client/dashboard')).fulfill({ json: fixture.pages['/dashboard'] });
        await page.locator('.asset-center').waitFor();

        await page.goto(origin + '/#/pages/screen/index?path=%2Faccount%2Fsettings');
        const settings = await waiting('/client/account/settings');
        await page.locator('.skeleton-full').waitFor();
        await page.locator('.skeleton-back').waitFor();
        await settings.fulfill({ json: { component: 'user/AccountSettings', props: {} } });
        const chunk = await waiting('settings-chunk');
        await page.locator('.skeleton-full').waitFor();
        recovering = true;
        await chunk.fulfill({ status: 503, contentType: 'text/javascript', body: '' });
        await page.getByText('Try again', { exact: true }).waitFor();
        await page.getByText('Try again', { exact: true }).click();
        await (await waiting('/client/account/settings')).fulfill({ json: { component: 'user/AccountSettings', props: {} } });
        await (await waiting('settings-chunk')).continue();
        await page.locator('.settings-list').waitFor();
        assert.equal(await page.locator('.settings-list').evaluate(el => getComputedStyle(el).display), 'grid', 'lazy screen styles loaded');
        assert.ok(scripts.some(path => /\/screens-Settings\./.test(path)), 'selected screen fetched lazily');
        assert.ok(!scripts.some(path => /\/screens-(PartnerStock|Kyc|WealthOrder)\./.test(path)), 'unrelated screens not downloaded');
        assert.deepEqual(errors, []);
        assert.ok(writes.every(path => ['/wallet/ensure', '/presence'].includes(path)));
        console.log(name + ': progressive entry, delayed data, data/module retries and lazy screen passed');
        await context.close();
    } finally { await browser.close(); }
}
