import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Existing local sign-in only. Clipboard and locale persistence are isolated in
// this browser test; no transfer, other funds movement, or user data is written.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ locale: 'zh-CN' });
await context.addInitScript(() => {
    window.accountIdClipboard = '';
    window.accountIdClipboardDenied = false;
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: async value => {
            if (window.accountIdClipboardDenied) throw new DOMException('Denied', 'NotAllowedError');
            window.accountIdClipboard = value;
        } },
    });
});
const page = await context.newPage();
const output = '/tmp/card-account-id-browser';
await mkdir(output, { recursive: true });
const errors = [];
let checks = 0;
page.on('pageerror', error => errors.push(error.message));
await page.route('**/*', route => {
    const request = route.request();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && new URL(request.url()).pathname !== '/login') {
        errors.push('Unexpected write');
        return route.abort();
    }
    return route.continue();
});
await page.route('**/locale', route => route.fulfill({ json: {} }));
async function inspect(name) {
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
        await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
        checks++;
    }
}
try {
    await page.goto('http://a.localhost:8000/login');
    await page.locator('input[type=email]').fill('user@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/dashboard');
    assert.equal(await page.locator('.user-quick-actions .user-quick-action').count(), 4);
    assert.equal(await page.getByText('推广', { exact: true }).count(), 0);
    await inspect('dashboard');

    await page.goto('http://a.localhost:8000/account');
    const account = page.getByLabel('账号 ID', { exact: true });
    const id = await account.textContent();
    assert.match(id, /^\d{12}$/);
    const registeredDate = `${id.slice(0, 4)}-${id.slice(4, 6)}-${id.slice(6, 8)}`;
    assert.equal(new Date(`${registeredDate}T00:00:00Z`).toISOString().slice(0, 10), registeredDate);
    assert.equal(await account.evaluate(node => node.tagName), 'CODE');
    assert.equal(await page.locator('.user-profile-avatar').count(), 0);
    assert.equal(await page.getByText('邀请好友', { exact: true }).count(), 0);
    await page.getByRole('button', { name: '复制账号 ID', exact: true }).click();
    await page.getByRole('status').filter({ hasText: '账号 ID 已复制。' }).waitFor();
    assert.equal(await page.evaluate(() => window.accountIdClipboard), id);
    await inspect('account-zh-CN');

    await page.getByRole('button', { name: '语言', exact: true }).first().click();
    await page.getByRole('menuitem', { name: 'English' }).click();
    await page.waitForFunction(() => document.documentElement.lang === 'en');
    assert.equal(await page.getByLabel('Account ID', { exact: true }).textContent(), id);
    await page.getByRole('status').filter({ hasText: 'Account ID copied.' }).waitFor();
    await inspect('account-en');

    await page.evaluate(() => { window.accountIdClipboardDenied = true; });
    await page.getByRole('button', { name: 'Copy account ID', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Could not copy.' }).waitFor();
    assert.deepEqual(await page.locator('#account-id').evaluate(identifier => ({
        focused: document.activeElement === identifier,
        selected: window.getSelection().toString(),
    })), { focused: true, selected: id });
    await page.setViewportSize({ width: 375, height: 1000 });
    await page.screenshot({ path: `${output}/copy-fallback-375.png`, fullPage: true });
    await page.reload();
    await page.locator('#account-id').waitFor();
    assert.equal(await page.locator('#account-id').textContent(), id);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, copyAndManualFallback: true, translations: true, screenshots: output }));
} finally {
    await browser.close();
}
