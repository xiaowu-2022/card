import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Real local admin sign-in; all settings/OTP writes are intercepted in this browser.
// No credentials are saved, SMS sent, users registered or production fixtures inserted.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ locale: 'zh-CN' });
const page = await context.newPage();
const base = 'http://a.localhost:8000';
const output = '/tmp/card-tenant-sms-browser';
await mkdir(output, { recursive: true });
const errors = [];
let checks = 0;
let interceptedOtp = 0;
page.on('pageerror', error => errors.push(error.message));
await page.route('**/*', route => {
    const request = route.request();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && new URL(request.url()).pathname !== '/admin/login') {
        errors.push('Unexpected write');
        return route.abort();
    }
    return route.continue();
});
await page.route('**/admin/locale', route => route.fulfill({ json: {} }));
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
    await page.goto(`${base}/admin/login`);
    await page.locator('input[type=email]').fill('owner@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.getByRole('button', { name: '登录', exact: true }).click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    await page.goto(`${base}/admin/settings/sms`);
    await page.locator('#sms-key-id').waitFor();
    assert.equal(await page.locator('#sms-key-id').inputValue(), '');
    assert.equal(await page.locator('#sms-key-secret').inputValue(), '');
    assert.equal(await page.locator('#sms-interval').inputValue(), '60');
    assert.equal(await page.locator('#sms-ttl').inputValue(), '600');
    assert.equal(await page.locator('#sms-enabled').getAttribute('aria-checked'), 'false');
    await inspect('settings-zh-CN');

    await page.locator('#sms-sign').fill('未保存的测试签名');
    await page.getByRole('button', { name: '语言', exact: true }).click();
    await page.getByRole('menuitem', { name: 'English' }).click();
    await page.waitForFunction(() => document.documentElement.lang === 'en');
    assert.equal(await page.locator('#sms-sign').inputValue(), '未保存的测试签名');
    await page.getByRole('heading', { name: 'Aliyun SMS', exact: true }).waitFor();
    await page.locator('#sms-sign').fill('');
    await inspect('settings-en');

    await page.route('**/admin/settings/sms', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        const data = route.request().postDataJSON();
        assert.equal(data.access_key_id, 'BrowserOnlyKey');
        assert.equal(data.access_key_secret, 'browser-only-secret');
        // Complete the UI request only. Never forward this POST to the application.
        await route.fulfill({ status: 303, headers: { location: '/admin/settings/sms' }, body: '' });
    });
    await page.locator('#sms-enabled').click();
    await page.locator('#sms-key-id').fill('BrowserOnlyKey');
    await page.locator('#sms-key-secret').fill('browser-only-secret');
    await page.locator('#sms-sign').fill('测试签名');
    await page.locator('#sms-template').fill('SMS_123456');
    await page.locator('#sms-password').fill('browser-only-password');
    await page.getByRole('button', { name: 'Save SMS settings', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#sms-key-secret')?.value === '');
    assert.equal(await page.locator('#sms-key-id').inputValue(), '');
    assert.equal(await page.locator('#sms-password').inputValue(), '');

    // Unconfigured company SMTP and SMS both fail closed.
    await page.goto(`${base}/register`);
    assert.equal(await page.getByRole('tab').count(), 0);
    await page.goto(`${base}/login`);
    await page.route('**/register', async route => {
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.registration = { emailAvailable: true, phoneAvailable: true };
        data.props.i18n.locale = 'zh-CN';
        await route.fulfill({ response, json: data });
    });
    await page.route('**/register/challenges', async route => {
        interceptedOtp++;
        const data = route.request().postDataJSON();
        assert.equal(data.channel, 'PHONE');
        assert.equal(data.region, 'CN');
        await route.fulfill({ status: 303, headers: { location: '/register' }, body: '' });
    });
    await page.locator('a[href="/register"]').first().click();
    await page.getByRole('tab', { name: '手机', exact: true }).click();
    await page.getByRole('combobox', { name: '国家代码', exact: true }).waitFor();
    await page.getByRole('combobox', { name: '国家代码', exact: true }).click();
    await page.getByRole('option').filter({ hasText: '+86' }).first().click();
    await page.locator('#destination').fill('1111');
    await page.getByRole('button', { name: '发送验证码', exact: true }).click();
    await page.getByText('请输入有效的手机号码。', { exact: true }).waitFor();
    assert.equal(interceptedOtp, 0);
    await inspect('phone-invalid-zh-CN');
    await page.locator('#destination').fill('13800138000');
    const submitted = page.waitForResponse(response => response.url().endsWith('/register/challenges') && response.request().method() === 'POST');
    await page.getByRole('button', { name: '发送验证码', exact: true }).click();
    await submitted;
    assert.equal(interceptedOtp, 1);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, isolatedBrowserFixtures: true, noRealSmsOrSettingsWrites: true, screenshots: output }));
} finally {
    await browser.close();
}
