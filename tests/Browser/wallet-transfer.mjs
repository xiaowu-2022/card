import assert from 'node:assert/strict';
import { chromium } from 'playwright';

// All financial POSTs are intercepted. Only sign-in reaches the live application.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ locale: 'zh-CN' });
    const errors = [], requests = [];
    let fixture;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        if (path === '/wallet/transfers' && request.method() === 'POST') {
            const data = request.postDataJSON();
            requests.push({ requestId: data.request_id, amount: data.amount, recipient: data.recipient_account_id });
            assert.equal(data.confirmed, true);
            assert.equal(data.amount, '10.25');
            assert.equal(data.recipient_account_id, '202609119999');
            const response = structuredClone(fixture);
            if (requests.length === 1) response.props.errors = { form: 'Your available balance is insufficient for this transfer.' };
            else {
                response.props.errors = {};
                response.props.receipt = { id: '00000000-0000-4000-8000-000000000123', requestId: data.request_id, amount: '10.25000000', asset: fixture.props.available.asset, sent: true, senderAccountId: fixture.props.accountId, recipientAccountId: data.recipient_account_id, createdAt: '2026-09-11T12:00:00Z' };
            }
            return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Inertia': 'true' }, body: JSON.stringify(response) });
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && path !== '/login') {
            if (path === '/locale') return route.fulfill({ json: {} });
            errors.push(`Blocked unexpected write: ${path}`);
            return route.abort();
        }
        return route.continue();
    });
    await page.goto('http://a.localhost:8000/login');
    await page.locator('input[type=email]').fill('user@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.getByRole('button', { name: /^(登录|Sign in)$/ }).click();
    await page.waitForURL('**/dashboard');
    assert.equal(await page.locator('.user-quick-actions .user-quick-action').count(), 4);
    const loaded = page.waitForResponse(response => new URL(response.url()).pathname === '/wallet/transfer' && response.request().headers()['x-inertia']);
    await page.locator('a[href="/wallet/transfer"]').first().click();
    fixture = await (await loaded).json();
    await page.locator('#recipient-account-id').waitFor();
    async function inspect(name) {
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
            await page.screenshot({ path: `/tmp/wallet-transfer-${name}-${width}.png`, fullPage: true });
        }
    }
    await inspect('form');
    await page.locator('#recipient-account-id').fill('202609119999');
    await page.locator('#transfer-amount').fill('1.001');
    await page.getByRole('button', { name: /^(核对转账|Review transfer)$/ }).click();
    assert.equal(requests.length, 0);
    assert.equal(await page.locator('#transfer-password').count(), 0);
    await page.locator('#transfer-amount').fill('10.25');
    await page.getByRole('button', { name: /^(核对转账|Review transfer)$/ }).click();
    await page.locator('#transfer-password').waitFor();
    await inspect('review');
    for (let attempt = 1; attempt <= 2; attempt++) {
        assert.ok(await page.getByRole('button', { name: /^(确认转账|Confirm transfer)$/ }).isDisabled());
        await page.locator('#transfer-password').fill('BrowserOnlyPassword');
        await page.getByRole('checkbox').check();
        await page.getByRole('button', { name: /^(确认转账|Confirm transfer)$/ }).click();
        if (attempt === 1) {
            await page.getByRole('alert').filter({ hasText: '可用余额不足' }).waitFor();
            assert.equal(await page.locator('#transfer-password').inputValue(), '');
            const persisted = await page.evaluate(() => JSON.stringify({ session: { ...sessionStorage }, history: history.state }));
            assert.ok(!persisted.includes('BrowserOnlyPassword'));
            assert.ok(persisted.includes(requests[0].requestId));
            // Reload recovers the same immutable intent, not the password.
            await page.reload();
            await page.locator('#transfer-password').waitFor();
            assert.equal(await page.locator('#transfer-password').inputValue(), '');
            assert.ok(await page.getByRole('button', { name: /^(修改|Edit)$/ }).isDisabled());
        }
    }
    await page.getByRole('heading', { name: /^(转账成功。|Transfer completed\.)$/ }).waitFor();
    assert.deepEqual(requests[0], requests[1]);
    assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter(key => key.startsWith('wallet-transfer:')).length), 0);
    await inspect('receipt');
    await page.getByRole('link', { name: /^(再次转账|New transfer)$/ }).click();
    await page.locator('#recipient-account-id').waitFor();
    assert.equal(await page.locator('#recipient-account-id').inputValue(), '');
    assert.equal(await page.locator('#transfer-amount').inputValue(), '');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: 9, liveFinancialWrites: 0, stableRequestAcrossRetryAndReload: true, clearsPasswords: true }));
} finally {
    await browser.close();
}
