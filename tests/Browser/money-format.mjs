import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Only login reaches the server as a mutation. Config saves are intercepted.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const output = '/tmp/card-money-format';
await mkdir(output, { recursive: true });
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [], saves = [];
    let fixture;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (request.method() === 'PUT' && path.startsWith('/admin/card-products/')) {
            saves.push(request.postDataJSON());
            return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Inertia': 'true' }, body: JSON.stringify(fixture) });
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && path !== '/admin/login') {
            errors.push(`Unexpected mutation: ${path}`);
            return route.abort();
        }
        if (fixture && path === '/admin/card-products' && request.headers()['x-inertia']) {
            return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Inertia': 'true' }, body: JSON.stringify(fixture) });
        }
        return route.continue();
    });
    await page.goto('http://a.localhost:8000/admin/login');
    await page.getByLabel('工作邮箱').fill('owner@a.localhost');
    await page.getByLabel('密码', { exact: true }).fill(process.env.ADMIN_I18N_TEST_PASSWORD ?? '123456');
    await page.getByRole('button', { name: /^(登录|Sign in)$/ }).click();
    await page.waitForURL(/\/admin\/(onboarding|demo)$/);
    await page.goto('http://a.localhost:8000/admin/card-products');
    await page.locator('input[id$="-fee"]').first().waitFor();
    assert.equal(await page.getByText('仅配置', { exact: true }).count(), 0);
    const amounts = await page.locator('input[id$="-fee"]').evaluateAll(inputs => inputs.map(input => input.value));
    for (const amount of amounts) assert.match(amount, /^\d+\.\d{2}$/);
    const text = await page.locator('main').innerText();
    assert.ok(!/\d+\.\d{3,}/.test(text), 'Visible money should have two decimals');
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px overflow`);
        await page.screenshot({ path: `${output}/card-products-${width}.png`, fullPage: true });
    }
    // Capture a genuine read-only Inertia response, then use browser-only precision fixtures.
    await page.goto('http://a.localhost:8000/admin/demo');
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/card-products' && response.request().headers()['x-inertia']);
    await page.locator('a[href="/admin/card-products"]').first().click();
    fixture = await (await response).json();
    assert.ok(fixture.props.products[0].config);
    fixture.props.products[0].config.openingFee = '2.12345678';
    await page.goto('http://a.localhost:8000/admin/demo');
    await page.locator('a[href="/admin/card-products"]').first().click();
    const fee = page.locator('input[id$="-fee"]').first();
    await fee.waitFor();
    assert.equal(await fee.inputValue(), '2.12');
    await fee.focus();
    await fee.blur();
    await page.locator('input[id$="-display"]').first().fill('Unsaved precision test');
    const unchangedSaved = page.waitForResponse(response => response.request().method() === 'PUT');
    await page.getByRole('button', { name: '保存销售配置', exact: true }).first().click();
    await unchangedSaved;
    assert.equal(saves[0].opening_fee, '2.12345678', 'Unchanged money must retain original precision');
    await fee.fill('3.45');
    await fee.blur();
    const saved = page.waitForResponse(response => response.request().method() === 'PUT');
    await page.getByRole('button', { name: '保存销售配置', exact: true }).first().click();
    await saved;
    assert.equal(saves[1].opening_fee, '3.45');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ passed: true, widths: [375, 768, 1440], exactUneditedPayload: true, configWrites: 0, screenshots: output }));
} finally { await browser.close(); }
