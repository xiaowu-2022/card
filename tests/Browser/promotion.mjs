import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Local login and GET only. Financial/settings/OTP writes never reach the server.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const base = 'http://a.localhost:8000';
const output = '/tmp/card-promotion-browser';
await mkdir(output, { recursive: true });
const errors = [];
let checks = 0;
let inertiaVersion = '';
async function newPage() {
    const context = await browser.newContext({ locale: 'zh-CN' });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
        const request = route.request();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && !['/login', '/admin/login'].includes(new URL(request.url()).pathname)) {
            errors.push('Unexpected live write blocked');
            return route.abort();
        }
        return route.continue();
    });
    return page;
}
async function inspect(page, name) {
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
        await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
        checks++;
    }
}
async function login(page, admin = false) {
    await page.goto(base + (admin ? '/admin/login' : '/login'));
    await page.locator('input[type=email]').fill(admin ? 'owner@a.localhost' : 'user@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.getByRole('button', { name: /^(登录|Sign in)$/ }).click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
}
try {
    const admin = await newPage();
    await login(admin, true);
    await admin.goto(base + '/admin/promotion');
    await admin.getByRole('heading', { name: '推广管理', exact: true }).waitFor();
    const companyCode = (await admin.getByRole('heading', { name: '公司邀请码', exact: true }).locator('..').locator('p').first().innerText()).trim();
    assert.match(companyCode, /^[0-9]{6}$/);
    await inspect(admin, 'admin-promotion');
    await admin.goto(base + '/admin/company-funds');
    await admin.getByRole('heading', { name: '公司总资金池账本', exact: true }).waitFor();
    await inspect(admin, 'company-fund-book');

    const user = await newPage();
    await login(user);
    await user.goto(base + '/promotion');
    await user.getByRole('heading', { name: '推广中心', exact: true }).waitFor();
    await inspect(user, 'promotion-empty');
    let fixtureTransfer = false;
    let lastRequestId;
    await user.route(new RegExp(`${base}/promotion(?:\\?.*)?$`), async route => {
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        if (route.request().method() === 'POST') {
            const input = route.request().postDataJSON();
            assert.equal(input.action, 'transfer');
            assert.equal(input.confirmed, true);
            assert.equal(input.current_password, 'browser-only-password');
            assert.match(input.request_id, /^[0-9a-f-]{36}$/);
            assert.equal(input.amount, undefined);
            lastRequestId = input.request_id;
            fixtureTransfer = true;
        }
        inertiaVersion = route.request().headers()['x-inertia-version'] ?? inertiaVersion;
        const response = await user.context().request.get(base + '/promotion', { headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia-Version': inertiaVersion } });
        assert.equal(response.status(), 200);
        const data = await response.json();
        const p = data.props.promotion;
        Object.assign(p, {
            supported: true, canTransfer: true, levelName: 'Level 3', availableCommission: fixtureTransfer ? '0.00000000' : '15.00000000', myCommission: '15.00000000',
            totals: { invited: 12, activated: 8, deposits: '400.00000000', commission: '45.00000000' },
            daily: { invited: 2, activated: 1, deposits: '50.00000000', commission: '15.00000000' },
            assignableLevels: [{ id: '11111111-1111-4111-8111-111111111111', name: 'Level 1' }], canAssign: true,
            direct: [{ id: '22222222-2222-4222-8222-222222222222', accountId: '202609114426', levelId: null }],
            details: [{ id: 'fixture-award', accountId: '202609114426', kind: 'Commission earned', amount: '15.00000000', occurredAt: '2026-09-11T00:05:00Z' }],
        });
        data.url = '/promotion';
        data.props.i18n.locale = 'zh-CN';
        await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: data });
    });
    await user.goto(base + '/account');
    await user.locator('a[href="/promotion"]').click();
    const transfer = user.getByRole('button', { name: '佣金转入余额', exact: true });
    await transfer.waitFor();
    await inspect(user, 'promotion-populated');
    await user.setViewportSize({ width: 375, height: 850 });
    await transfer.click();
    const dialog = user.getByRole('dialog');
    await dialog.waitFor();
    const confirm = dialog.getByRole('button', { name: '确认', exact: true });
    assert.equal(await confirm.isDisabled(), true);
    await dialog.locator('input[type=password]').fill('must-be-cleared');
    await user.keyboard.press('Escape');
    await transfer.click();
    assert.equal(await dialog.locator('input[type=password]').inputValue(), '');
    await dialog.locator('input[type=password]').fill('browser-only-password');
    await dialog.getByRole('checkbox').check();
    await user.screenshot({ path: `${output}/commission-confirmation-375.png`, fullPage: true });
    await confirm.click();
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(await transfer.isDisabled(), true);
    assert.ok(fixtureTransfer && lastRequestId);
    assert.ok(!(await user.evaluate(() => JSON.stringify(history.state))).includes('browser-only-password'));

    const registration = await newPage();
    // Exercise the real server link binding without sending an OTP or registering a user.
    const response = await registration.context().request.get(`${base}/register?invite=${companyCode}`, { headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia-Version': inertiaVersion } });
    assert.equal(response.status(), 200);
    const initial = await response.json();
    assert.equal(initial.props.registration.invitationLocked, true);
    assert.equal(initial.props.registration.invitationCode, companyCode);
    await registration.goto(base + '/login');
    await registration.route('**/register', async route => {
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.registration.emailAvailable = true;
        data.props.registration.phoneAvailable = true;
        data.props.i18n.locale = 'zh-CN';
        await route.fulfill({ response, json: data });
    });
    await registration.locator('a[href="/register"]').click();
    await registration.locator('#invitation-code').waitFor();
    assert.equal(await registration.locator('#invitation-code').inputValue(), companyCode);
    assert.equal(await registration.locator('#invitation-code').evaluate(el => el.readOnly), true);
    await inspect(registration, 'registration-linked');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, linkedInvitationLocked: true, transferConfirmedAndPasswordCleared: true, liveFinancialWrites: 0, screenshots: output }));
} finally {
    await browser.close();
}
