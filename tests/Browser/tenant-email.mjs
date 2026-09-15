import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Only local Admin login is forwarded. Configuration saves and test/OTP sends below
// are browser-only response fixtures; no real credential changes or email delivery.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ locale: 'zh-CN' });
const page = await context.newPage();
const base = 'http://a.localhost:8000';
const output = '/tmp/card-tenant-email-browser';
await mkdir(output, { recursive: true });
const errors = [];
let checks = 0, saved = false, sent = false, adminLocale = 'zh-CN';
page.on('pageerror', error => errors.push(error.message));
await page.route('**/*', route => {
    const request = route.request();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && new URL(request.url()).pathname !== '/admin/login') {
        errors.push('Unexpected write');
        return route.abort();
    }
    return route.continue();
});
await page.route('**/admin/locale', route => {
    adminLocale = route.request().postDataJSON().locale;
    return route.fulfill({ json: {} });
});
async function inspect(name) {
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
        await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
        checks++;
    }
}
async function configuredPage(route) {
    const response = await context.request.get(`${base}/admin/settings/email`, {
        headers: {
            'X-Inertia': 'true',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia-Version': route.request().headers()['x-inertia-version'] ?? '',
        },
    });
    assert.equal(response.status(), 200, 'Read-only settings fixture request failed');
    const data = await response.json();
    data.props.i18n.locale = adminLocale;
    data.props.settings.email = { enabled: true, tokenConfigured: true, fromAddress: 'sender@example.test', fromName: 'Browser test', dailyRecipientLimit: 10 };
    if (sent) data.props.flash.success = 'Proton accepted the test email. Check the recipient inbox and spam folder.';
    await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: data });
}
try {
    await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[type=email]').fill('owner@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.getByRole('button', { name: '登录', exact: true }).click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    await page.goto(`${base}/admin/settings/email`, { waitUntil: 'domcontentloaded' });
    await page.locator('#email-token').waitFor();
    assert.equal(await page.locator('#email-token').inputValue(), '');
    assert.equal(await page.locator('#email-host').inputValue(), 'smtp.protonmail.ch');
    assert.equal(await page.locator('#email-encryption').inputValue(), 'STARTTLS');
    assert.equal(await page.locator('#email-port').inputValue(), '587');
    assert.equal(await page.locator('#email-limit').inputValue(), '10');
    assert.equal(await page.getByRole('button', { name: '发送测试邮件', exact: true }).isDisabled(), true);
    await inspect('email-zh-CN');

    await page.locator('#email-name').fill('未保存的名称');
    await page.getByRole('button', { name: '语言', exact: true }).click();
    await page.getByRole('menuitem', { name: 'English' }).click();
    await page.waitForFunction(() => document.documentElement.lang === 'en');
    assert.equal(await page.locator('#email-name').inputValue(), '未保存的名称');
    await page.locator('#email-name').fill('');
    await inspect('email-en');

    await page.route('**/admin/settings/email', async route => {
        if (route.request().method() === 'POST') {
            const data = route.request().postDataJSON();
            assert.equal(data.smtp_token, 'browser-only-token');
            assert.equal(data.from_address, 'sender@example.test');
            assert.equal(data.host, undefined);
            saved = true;
            return configuredPage(route);
        }
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.i18n.locale = adminLocale;
        if (saved) data.props.settings.email = { enabled: true, tokenConfigured: true, fromAddress: 'sender@example.test', fromName: 'Browser test', dailyRecipientLimit: 10 };
        if (sent) data.props.flash.success = 'Proton accepted the test email. Check the recipient inbox and spam folder.';
        await route.fulfill({ response, json: data });
    });
    await page.route('**/admin/settings/email/test', async route => {
        const data = route.request().postDataJSON();
        assert.equal(saved, true);
        assert.equal(data.test_email, 'recipient@example.test');
        assert.equal(data.smtp_token, undefined);
        assert.match(data.request_id, /^[a-f0-9-]{36}$/);
        sent = true;
        await configuredPage(route);
    });
    await page.locator('#email-enabled').click();
    await page.locator('#email-account').fill('sender@example.test');
    assert.equal(await page.locator('#email-from').inputValue(), 'sender@example.test');
    await page.locator('#email-name').fill('Browser test');
    await page.locator('#email-token').fill('browser-only-token');
    await page.locator('#email-password').fill('browser-only-password');
    await page.getByRole('button', { name: 'Save email settings', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#email-token')?.value === '' && document.querySelector('#email-password')?.value === '');
    await page.getByText('Token configured. Leave blank to keep it; enter a new token to replace it.', { exact: true }).waitFor();
    await page.locator('#email-test-recipient').fill('recipient@example.test');
    await page.locator('#email-test-password').fill('browser-only-password');
    await page.getByRole('button', { name: 'Send test email', exact: true }).click();
    await page.getByText('Proton accepted the test email. Check the recipient inbox and spam folder.', { exact: true }).first().waitFor();
    assert.equal(await page.locator('#email-test-password').inputValue(), '');
    assert.equal(sent, true);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, languageDraftPreservation: true, secretClearing: true, noRealEmailOrSettingsWrites: true, screenshots: output }));
} catch (error) {
    await page.screenshot({ path: `${output}/failure.png`, fullPage: true });
    throw error;
} finally {
    await browser.close();
}
