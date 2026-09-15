import assert from 'node:assert/strict';
import { chromium } from 'playwright';

// Browser-only creation responses. Never provision an actual administrator for visual QA.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage();
    const errors = [];
    let fixture, attempts = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (path === '/admin/team/administrators' && request.method() === 'POST') {
            attempts++;
            const data = request.postDataJSON();
            assert.equal(data.email, 'browser-only@example.test');
            assert.equal(data.role, 'TENANT_ADMIN');
            const response = structuredClone(fixture);
            response.props.errors = attempts === 1 ? { form: 'This login account is unavailable. Choose another account.' } : {};
            return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Inertia': 'true' }, body: JSON.stringify(response) });
        }
        if (!['GET','HEAD','OPTIONS'].includes(request.method()) && path !== '/admin/login') {
            errors.push(`Unexpected mutation blocked: ${path}`);
            return route.abort();
        }
        return route.continue();
    });
    await page.goto('http://a.localhost:8000/admin/login');
    await page.getByLabel('工作邮箱').fill('owner@a.localhost');
    await page.getByLabel('密码', { exact: true }).fill(process.env.ADMIN_I18N_TEST_PASSWORD ?? '123456');
    await page.getByRole('button', { name: /^(登录|Sign in)$/ }).click();
    await page.waitForURL(/\/admin\/(onboarding|demo)$/);
    const loaded = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/team' && response.request().headers()['x-inertia']);
    await page.locator('a[href="/admin/team"]').first().click();
    fixture = await (await loaded).json();
    await page.locator('#admin-password').waitFor();
    assert.equal(await page.getByRole('button', { name: '发送邀请', exact: true }).count(), 0);
    for (const width of [375,768,1440]) {
        await page.setViewportSize({ width, height: 1050 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({ path: `/tmp/create-admin-${width}.png`, fullPage: true });
    }
    await page.locator('#admin-name').fill('Browser Only');
    await page.locator('#invite-email').fill('browser-only@example.test');
    async function fillPasswords() {
        await page.locator('#admin-password').fill('BrowserPassword123');
        await page.locator('#admin-password-confirmation').fill('BrowserPassword123');
        await page.locator('#admin-current-password').fill('BrowserActor123');
    }
    for (let attempt = 1; attempt <= 2; attempt++) {
        await fillPasswords();
        assert.ok(!(await page.evaluate(() => JSON.stringify(history.state))).includes('BrowserPassword123'));
        await page.getByRole('button', { name: '创建管理员', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#admin-password')?.value === '');
        assert.equal(await page.locator('#admin-current-password').inputValue(), '');
        assert.equal(await page.locator('#admin-password-confirmation').inputValue(), '');
        if (attempt === 1) {
            await page.getByRole('alert').filter({ hasText: '该登录账号不可用，请使用其他账号。' }).waitFor();
            assert.equal(await page.locator('#invite-email').inputValue(), 'browser-only@example.test');
        }
    }
    assert.equal(await page.locator('#invite-email').inputValue(), '');
    assert.equal(attempts, 2);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveWidths: [375,768,1440], clearPasswordsOnSuccessAndError: true, liveAdminCreations: 0 }));
} finally { await browser.close(); }
