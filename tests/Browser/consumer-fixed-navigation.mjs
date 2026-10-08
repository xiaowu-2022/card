import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5217';
const out = 'artifacts/uni-parity/fixed-navigation';
mkdirSync(out, { recursive: true });
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
        const page = await context.newPage();
        const errors = [], mutations = [];
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', route => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                if (req.method() !== 'GET') { mutations.push(url.pathname); return route.fulfill({ json: {} }); }
                const key = url.pathname.slice(7);
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (key === '/unread') return route.fulfill({ json: { messages: 2, support: 1 } });
                if (key === '/client/cards') return route.fulfill({ json: fixture.pages['/cards?fixture=cards'] });
                if (key.startsWith('/client/')) return route.fulfill({ json: fixture.pages[key.slice(7)] ?? {} });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (url.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        async function aligned(label) {
            const tabs = page.locator('.tabs:visible'), header = page.locator('.header:visible, .brand-header:visible');
            await tabs.first().waitFor();
            const accountHome = await page.locator('.shell.account-shell:visible').count() > 0;
            if (!accountHome) await header.first().waitFor();
            assert.equal(await tabs.count(), 1, label + ': exactly one bottom bar');
            assert.equal(await header.count(), accountHome ? 0 : 1, label + ': account home omits the header');
            const nav = await tabs.boundingBox(), top = accountHome ? null : await header.boundingBox();
            const viewport = await page.evaluate(() => ({ width: innerWidth, height: innerHeight }));
            assert.ok(Math.abs(nav.y + nav.height - viewport.height) < 2, JSON.stringify({ label, nav, viewport }));
            if (top) assert.ok(Math.abs(top.y) < 2, JSON.stringify({ label, top }));
            assert.ok(nav.x >= 0 && nav.x + nav.width <= viewport.width + 1 && nav.height >= 60, label);
            // Must remain tappable, not merely have the right computed coordinates.
            assert.ok(await tabs.evaluate(el => {
                const r = el.getBoundingClientRect();
                return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
            }), label + ': hit target');
        }
        await page.goto(origin + '/#/pages/cards/index');
        await page.locator('.tabs').waitFor();
        // Simulate an embedded viewport clipped by 24px without resizing its
        // layout viewport. Ordinary browser resize tests cannot cover this.
        await page.evaluate(() => {
            Object.defineProperty(window.visualViewport, 'height', { configurable: true, get: () => innerHeight - 24 });
            window.visualViewport.dispatchEvent(new Event('resize'));
        });
        await page.waitForTimeout(80);
        const clipped = await page.evaluate(() => {
            const tabs = document.querySelector('.tabs');
            const bottom = innerHeight - 24;
            return {
                bottom: tabs.getBoundingClientRect().bottom,
                visibleBottom: bottom,
                labelsVisible: [...tabs.querySelectorAll('.tab > uni-text')].every(el => el.getBoundingClientRect().bottom <= bottom),
                tappable: tabs.contains(document.elementFromPoint(innerWidth / 2, bottom - 12)),
                background: getComputedStyle(document.documentElement).backgroundColor,
            };
        });
        assert.ok(Math.abs(clipped.bottom - clipped.visibleBottom) < 2, JSON.stringify(clipped));
        assert.ok(clipped.labelsVisible && clipped.tappable, 'clipped viewport keeps labels and hit targets visible');
        assert.equal(clipped.background, 'rgb(255, 255, 255)');
        await page.evaluate(() => {
            delete window.visualViewport.height;
            window.visualViewport.dispatchEvent(new Event('resize'));
        });
        await aligned('visible viewport restored');
        for (const ancestor of ['.user-root', 'uni-page-wrapper']) {
            for (const property of ['transform', 'contain', 'willChange']) {
                await page.evaluate(({ ancestor, property }) => {
                    const el = document.querySelector(ancestor);
                    assertExists(el);
                    function assertExists(value) { if (!value) throw new Error('Missing ancestor'); }
                    el.style[property] = property === 'transform' ? 'translateZ(0)' : property === 'contain' ? 'layout' : 'transform';
                    if (!document.querySelector('#navigation-long-page')) {
                        const spacer = document.createElement('div'); spacer.id = 'navigation-long-page'; spacer.style.height = '4000px';
                        document.querySelector('.shell-main').append(spacer);
                    }
                }, { ancestor, property });
                for (const y of [0, 800, 3000]) {
                    await page.evaluate(y => window.scrollTo(0, y), y);
                    await page.waitForTimeout(60);
                    await aligned(`${ancestor}/${property}/${y}`);
                }
                await page.evaluate(({ ancestor, property }) => { document.querySelector(ancestor).style[property] = ''; }, { ancestor, property });
            }
        }
        for (const viewport of [{ width: 320, height: 640 }, { width: 430, height: 920 }, { width: 844, height: 390 }, { width: 390, height: 550 }]) {
            await page.setViewportSize(viewport);
            await page.waitForTimeout(80);
            await aligned('resize ' + JSON.stringify(viewport));
        }
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: `${out}/${name}-long-page.png` });
        // reLaunch drops the previous page, then navigateTo retains it in the page stack.
        await page.locator('.tabs .tab').filter({ hasText: /^Me$/ }).click();
        await page.waitForURL(/pages\/account\/index/);
        await aligned('account');
        // A generic detail page has a title header and Messages action.
        await page.goto(origin + '/#/pages/screen/index?path=%2Fpromotion%2Fdaily');
        await page.locator('.header [aria-label="Messages"]').waitFor();
        await page.locator('.header [aria-label="Messages"]').click();
        await page.waitForURL(/pages\/messages\/index/);
        await page.waitForTimeout(150);
        await aligned('cached page hidden');
        await page.goBack();
        await page.waitForURL(/promotion/);
        await page.waitForTimeout(150);
        await aligned('cached page restored');
        assert.equal(await page.locator('.tabs').count(), 1, 'hidden pages must not retain body navigation');
        // Opening a modal keeps bars behind the mask and closing restores their position.
        await page.goto(origin + '/#/pages/cards/index');
        const reload = page.locator('button,uni-button').filter({ hasText: /^Reload$/ }).first();
        await reload.waitFor(); await reload.click();
        const dialog = page.locator('[role="dialog"]'); await dialog.waitFor();
        assert.ok(await page.evaluate(() => document.elementFromPoint(10, innerHeight - 10)?.closest('.modal-backdrop')));
        await dialog.locator('[aria-label="Close"]').click();
        await aligned('modal closed');
        // At maximum scroll the final content must clear every fixed bottom control.
        for (const path of ['/dashboard', '/account', '/kyc', '/promotion']) {
            await page.goto(origin + '/#/pages/screen/index?path=' + encodeURIComponent(path));
            await page.locator('.shell-main').waitFor();
            await page.waitForTimeout(200);
            for (const width of [320, 390, 750]) {
                await page.setViewportSize({ width, height: 640 });
                await page.evaluate(() => {
                    const main = document.querySelector('.shell-main');
                    let marker = main.querySelector('#last-content');
                    if (!marker) {
                        marker = document.createElement('div');
                        marker.id = 'last-content';
                        marker.style.height = '1500px';
                        // Keep the promotion hub's own dock clearance after its last content.
                        (main.querySelector('.hub') || main).append(marker);
                    }
                    window.scrollTo(0, document.documentElement.scrollHeight);
                });
                await page.waitForTimeout(60);
                const bounds = await page.evaluate(() => {
                    const main = document.querySelector('.shell-main');
                    const style = getComputedStyle(main);
                    const end = document.querySelector('#last-content').getBoundingClientRect();
                    const bar = document.querySelector('.share-dock') || document.querySelector('.tabs');
                    return { left: parseFloat(style.paddingLeft), right: parseFloat(style.paddingRight),
                        gap: bar.getBoundingClientRect().top - end.bottom };
                });
                assert.ok(bounds.left >= 20 && bounds.right >= 20, JSON.stringify({ path, width, bounds }));
                assert.ok(bounds.gap >= 47, JSON.stringify({ path, width, bounds }));
            }
        }
        assert.deepEqual(errors, []);
        assert.ok(mutations.every(path => ['/api/v1/wallet/ensure', '/api/v1/messages/read-all', '/api/v1/presence'].includes(path)), JSON.stringify(mutations));
        await context.close();
        console.log(`PASS ${name}: fixed long-page bars, transformed ancestors, resizing, page lifecycle and modal layering`);
    } finally { await browser.close(); }
}
