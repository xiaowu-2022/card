import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5217';
const out = 'artifacts/uni-parity/input-modal';
mkdirSync(out, { recursive: true });
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 750 }, isMobile: true, deviceScaleFactor: 1 });
        const page = await context.newPage();
        const errors = [], mutations = [];
        let loggedIn = true;
        page.on('pageerror', e => errors.push(e.message));
        await context.route('**/*', route => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                if (req.method() !== 'GET') { mutations.push(url.pathname); return route.fulfill({ json: {} }); }
                const key = url.pathname.replace('/api/v1', '');
                if (key === '/bootstrap') return route.fulfill({ json: { ...fixture[loggedIn ? 'authenticated' : 'guest'], locale: 'en' } });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
                if (key.startsWith('/client/cards')) return route.fulfill({ json: fixture.pages['/cards?fixture=cards'] });
                return route.fulfill({ status: 404, json: {} });
            }
            if (url.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/#/pages/cards/index');
        const reload = page.locator('button,uni-button').filter({ hasText: /^Reload$/ }).first();
        await reload.waitFor();
        // A tall transformed ancestor used to make fixed descendants center on the page.
        await page.evaluate(() => {
            const root = document.querySelector('.user-root');
            root.style.transform = 'translateZ(0)';
            const spacer = document.createElement('div'); spacer.style.height = '2500px'; root.append(spacer);
            window.scrollTo(0, 650);
        });
        const scrollBefore = await page.evaluate(() => window.scrollY);
        await reload.evaluate(el => el.click());
        const dialog = page.locator('[role="dialog"]');
        await dialog.waitFor();
        assert.equal(await dialog.evaluate(el => el.parentElement.parentElement === document.body), true);
        let box = await dialog.boundingBox();
        const viewport = await page.evaluate(() => ({ y: visualViewport.offsetTop, h: visualViewport.height }));
        assert.ok(Math.abs(box.y + box.height / 2 - viewport.y - viewport.h / 2) < 3, JSON.stringify({ box, viewport }));
        const amount = dialog.locator('input[aria-label="Card operation amount"]');
        await amount.fill('30.12345678');
        await amount.focus();
        assert.equal(await amount.inputValue(), '30.12345678');
        assert.equal(await amount.getAttribute('inputmode'), 'decimal');
        const paint = await amount.evaluate(el => ({ wrapper: el.parentElement.tagName, color: getComputedStyle(el).webkitTextFillColor, height: el.getBoundingClientRect().height }));
        assert.notEqual(paint.wrapper, 'UNI-INPUT');
        assert.equal(paint.color, 'rgb(37, 36, 31)');
        assert.ok(paint.height >= 40);
        await page.screenshot({ path: `${out}/${name}-amount.png` });
        // Emulate the visual viewport shrinking/panning above the software keyboard.
        await page.evaluate(() => {
            Object.defineProperty(visualViewport, 'height', { configurable: true, get: () => 340 });
            Object.defineProperty(visualViewport, 'offsetTop', { configurable: true, get: () => 65 });
            visualViewport.dispatchEvent(new Event('resize'));
        });
        await page.waitForTimeout(100);
        box = await dialog.boundingBox();
        assert.ok(box.y >= 65 && box.y + box.height <= 405, JSON.stringify(box));
        assert.ok(Math.abs(box.y + box.height / 2 - 235) < 3);
        assert.equal(await amount.inputValue(), '30.12345678');
        await page.screenshot({ path: `${out}/${name}-keyboard.png` });
        await amount.blur();
        const submit = dialog.locator('button,uni-button').filter({ hasText: /^Reload$/ }).last();
        await submit.scrollIntoViewIfNeeded();
        const submitBox = await submit.boundingBox();
        assert.ok(submitBox.y >= box.y && submitBox.y + submitBox.height <= box.y + box.height);
        assert.equal(await page.evaluate(() => document.body.style.position), 'fixed');
        await dialog.locator('[aria-label="Close"]').click();
        assert.equal(await page.evaluate(() => document.body.style.position), '');
        assert.ok(Math.abs((await page.evaluate(() => window.scrollY)) - scrollBefore) < 3);
        loggedIn = false;
        await page.goto(origin + '/#/pages/login/index');
        await page.locator('.auth-root').waitFor();
        // Check the initial login paint before focusing any field. The backdrop
        // must cover the viewport independently of the login content's height.
        for (const height of [640, 950]) {
            await page.setViewportSize({ width: 390, height });
            const background = page.locator('.auth-viewport-background');
            const rect = await background.boundingBox();
            assert.ok(Math.abs(rect.y) < 1 && Math.abs(rect.height - height) < 1);
            assert.equal(await background.evaluate(el => getComputedStyle(el).pointerEvents), 'none');
            assert.equal(await background.evaluate(el => el.parentElement.parentElement === document.body), true);
        }
        await page.setViewportSize({ width: 390, height: 750 });
        const password = page.locator('input[aria-label="Password"]');
        await password.fill('OfflinePassword123');
        await password.focus();
        assert.equal(await password.getAttribute('type'), 'password');
        assert.equal(await password.inputValue(), 'OfflinePassword123');
        await page.locator('[aria-label="Show password"]').click();
        assert.equal(await password.getAttribute('type'), 'text');
        assert.equal(await password.inputValue(), 'OfflinePassword123');
        await page.screenshot({ path: `${out}/${name}-password.png` });
        assert.deepEqual(errors, []);
        assert.ok(mutations.every(path => path === '/api/v1/wallet/ensure'), JSON.stringify(mutations));
        await context.close();
        console.log('PASS ' + name + ': input visibility, viewport placement, scroll restore, no financial submissions');
    } finally { await browser.close(); }
}
