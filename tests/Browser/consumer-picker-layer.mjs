import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5217';
mkdirSync('artifacts/uni-parity/picker-layer', { recursive: true });
for (const [name, engine, options] of [
    ['chromium', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({
            viewport: { width: 390, height: 750 },
            isMobile: true,
            hasTouch: true,
        });
        const page = await context.newPage();
        const errors = [],
            mutations = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('**/*', (route) => {
            const req = route.request(),
                u = new URL(req.url());
            if (u.pathname.startsWith('/api/v1')) {
                if (req.method() !== 'GET') {
                    mutations.push(u.pathname);
                    return route.fulfill({ json: {} });
                }
                const key = u.pathname.slice(7);
                if (key === '/bootstrap')
                    return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (key === '/client/promotion/daily')
                    return route.fulfill({ json: fixture.pages['/promotion/daily'] });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (u.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/#/pages/screen/index?path=%2Fpromotion%2Fdaily');
        await page
            .locator('button,uni-button')
            .filter({ hasText: /^Filters$/ })
            .click();
        const dialog = page.locator('[role="dialog"]');
        const trigger = dialog.locator('uni-picker');
        await trigger.click();
        const picker = page.locator('.uni-picker-container:visible');
        await picker.waitFor();
        const wheel = picker.locator('uni-picker-view-column');
        await wheel.waitFor();
        // Hit testing proves the wheel and its confirmation control are above both modal layers.
        for (const target of [wheel, picker.locator('.uni-picker-action-confirm')]) {
            await target.scrollIntoViewIfNeeded();
            await page.waitForTimeout(350);
            assert.equal(
                await target.evaluate((el) => {
                    const r = el.getBoundingClientRect();
                    return el.contains(
                        document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2),
                    );
                }),
                true,
            );
        }
        const start = await wheel.locator('.uni-picker-view-content').getAttribute('style');
        // Touch drag is dispatched to the actual topmost hit element (not through the modal).
        await wheel.evaluate(async (el) => {
            const r = el.getBoundingClientRect(),
                x = r.x + r.width / 2,
                y = r.y + r.height / 2;
            const target = document.elementFromPoint(x, y);
            const fire = (type, dy, end = false) => {
                const t = {
                    identifier: 1,
                    target,
                    clientX: x,
                    clientY: y + dy,
                    pageX: x,
                    pageY: y + dy,
                    screenX: x,
                    screenY: y + dy,
                };
                const e = new Event(type, { bubbles: true, cancelable: true });
                Object.defineProperties(e, {
                    touches: { value: end ? [] : [t] },
                    changedTouches: { value: [t] },
                    targetTouches: { value: end ? [] : [t] },
                });
                target.dispatchEvent(e);
            };
            const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            fire('touchstart', 0);
            for (const dy of [-12, -28, -46, -73]) {
                await pause(80);
                fire('touchmove', dy);
            }
            await pause(250);
            fire('touchend', -73, true);
        });
        await page.waitForTimeout(1500);
        assert.notEqual(
            await wheel.locator('.uni-picker-view-content').getAttribute('style'),
            start,
        );
        await page.screenshot({ path: `artifacts/uni-parity/picker-layer/${name}-wheel.png` });
        await picker.locator('.uni-picker-action-confirm').click();
        await picker.waitFor({ state: 'hidden' });
        assert.notEqual((await trigger.innerText()).trim(), 'All activity');
        const selection = await trigger.innerText();
        await trigger.click();
        await picker.locator('.uni-picker-action-cancel').click();
        await picker.waitFor({ state: 'hidden' });
        assert.equal(await trigger.innerText(), selection);
        assert.equal(await page.evaluate(() => document.body.style.position), 'fixed');
        await dialog.locator('[aria-label="Close"]').click();
        assert.equal(await page.evaluate(() => document.body.style.position), '');
        assert.deepEqual(errors, []);
        assert.ok(mutations.every((p) => p === '/api/v1/wallet/ensure'));
        console.log(
            'PASS ' + name + ': picker hit targets, touch drag, confirm/cancel, page unlock',
        );
    } finally {
        await browser.close();
    }
}
