import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// Entire browser/session/provider state is synthetic. No server or provider requests escape.
const origin = 'http://admin.localhost:8000';
const out = 'artifacts/u1-standard-provider';
mkdirSync(out, { recursive: true });
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const id = '11111111-1111-4111-8111-111111111111';
const types = ['openvcc.success', 'openvcc.failed', 'updatevcc.success', 'otp', 'rechargevcc.success', 'rechargevcc.failed', 'withdrawvcc.success', 'withdrawvcc.fail', 'transaction'];
const callback = 'https://test.example.com/webhooks/u1/22222222-2222-4222-8222-222222222222';
for (const [name, engine, launch] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...launch });
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const page = await context.newPage();
        page.setDefaultTimeout(15000);
        const errors = [], unexpected = [], sends = [], requests = [], notices = [];
        let providers = [], created = false, inFlight = 0, maximumInFlight = 0, recordReads = 0, accountChecks = 0;
        const props = () => ({
            errors: {}, publicAssets: [], unreadSupport: 0, unreadMessages: 0,
            requestId: '33333333-3333-4333-8333-333333333333',
            i18n: { surface: 'platform', locale: 'zh-CN', enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Shanghai' },
            tenant: null, auth: { admin: { name: '测试管理员', permissions: ['provider_operation.read', 'card_provider_reference.manage'] }, user: null },
            flash: { success: null },
            providers: { data: providers, total: providers.length, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        });
        const state = () => ({ component: 'platform/CardProviders', props: props(), url: '/platform/card-providers', version: 'fixture' });
        page.on('pageerror', (error) => errors.push(error.message));
        await context.route('**/*', async (route) => {
            const req = route.request(), url = new URL(req.url()), path = url.pathname, method = req.method();
            if (url.origin !== origin) { unexpected.push(req.url()); return route.abort(); }
            if (path.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + path), contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript' });
            if (path === '/platform/card-providers' && method === 'POST') {
                const data = req.postDataJSON();
                assert.equal(data.provider_type, 'U1');
                assert.equal(data.customer_no, 'SYNTHETIC-CUSTOMER');
                assert.equal(data.api_key, 'synthetic-key-not-real');
                assert.ok(!('version' in data));
                created = true;
                providers = [{ id, name: data.name, version: 1, photonpay: null, localMock: false, apiConnected: false, sandbox: false, referenceBalance: null, asset: 'USDT', issuedCardCount: '0', updatedAt: new Date().toISOString(), canDelete: true,
                    u1: { customerNo: data.customer_no, baseUrl: 'https://api.uucard.cyou/v1', callbackUrl: callback, provider: 'U1', enabled: false, zeroFeesConfirmed: false, checkStatus: 'UNCHECKED', checkedAt: null, groups: [], types } }];
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state() });
            }
            if (path === '/platform/card-providers' && method === 'GET') {
                if (req.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state() });
                return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state()).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
            }
            if (path === `/platform/card-providers/${id}/check` && method === 'POST') {
                accountChecks++;
                providers[0].u1.checkStatus = 'VERIFIED';
                providers[0].u1.groups = [{ groupNo: 'GROUP-SHARE', bin: '412345', currency: 'USD', model: 'SHARE' }];
                return route.fulfill({ json: { checked: true } });
            }
            if (path === `/platform/card-providers/${id}/u1/tests` && method === 'POST') {
                const data = req.postDataJSON();
                inFlight++; maximumInFlight = Math.max(maximumInFlight, inFlight);
                sends.push(data.notification_type);
                const result = { id: data.request_id, type: data.notification_type, status: 'ACCEPTED', notificationStatus: 'WAITING', notificationId: null, response: { resultCode: '0000', sign: '[REDACTED]' } };
                requests.unshift(result);
                const noticeId = String(notices.length + 1);
                notices.unshift({ id: noticeId, notification_type: data.notification_type, verified: true, verification_reason: 'VERIFIED', delivery_count: 1, received_at: new Date().toISOString(), last_received_at: new Date().toISOString() });
                inFlight--;
                return route.fulfill({ json: result });
            }
            if (path === `/platform/card-providers/${id}/u1/notifications` && method === 'GET') {
                recordReads++;
                const pageNo = Number(url.searchParams.get('page') || 1);
                return route.fulfill({ json: { items: pageNo === 1 ? notices : [], page: pageNo, pages: notices.length ? 2 : 1, total: notices.length ? 21 : 0,
                    requests: requests.slice(0, 9).map((item) => ({ ...item, notificationStatus: 'RECEIVED' })) } });
            }
            if (path.startsWith(`/platform/card-providers/${id}/u1/notifications/`) && method === 'GET') {
                return route.fulfill({ json: { id: path.split('/').at(-1), type: 'transaction', verified: true, reason: 'VERIFIED', processingStatus: 'UNMAPPED', deliveries: 2, receivedAt: new Date().toISOString(), lastReceivedAt: new Date().toISOString(), signedFields: ['type', 'timestamp'],
                    fields: { cardno: 'RANDOM-CARD', amount: '-0.00000001', currency: 'USD', free: '123456789012.12345678', billingamount: '0' },
                    payload: { type: 'transaction', amount: '-0.00000001', free: '123456789012.12345678', otp: '[REDACTED]', unknown: { future: 'PRESERVED-UNKNOWN-FIELD' } } } });
            }
            if (['/favicon.ico', '/platform/locale'].includes(path)) return route.fulfill({ status: 204 });
            unexpected.push(`${method} ${path}`);
            return route.fulfill({ status: 404, json: {} });
        });
        await page.goto(origin + '/platform/card-providers');
        await page.getByRole('button', { name: '新增卡商', exact: true }).click();
        let dialog = page.getByRole('dialog');
        await dialog.getByLabel('卡商类型').selectOption('U1');
        await dialog.getByLabel('卡商名称', { exact: true }).fill('U1 synthetic fixture');
        await dialog.getByLabel('customerNo', { exact: true }).fill('SYNTHETIC-CUSTOMER');
        await dialog.getByLabel('apiKey', { exact: true }).fill('synthetic-key-not-real');
        await dialog.getByRole('button', { name: '保存', exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        assert.ok(created);
        await page.getByRole('button', { name: '确定', exact: true }).click();
        const row = page.getByRole('row').filter({ hasText: 'U1 synthetic fixture' });
        await row.getByRole('button', { name: '编辑', exact: true }).click();
        dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: '发送测试通知', exact: true }).waitFor();
        assert.equal(await dialog.getByLabel('apiKey', { exact: true }).inputValue(), '');
        assert.equal(await dialog.getByLabel('回调地址', { exact: true }).inputValue(), callback);
        assert.equal(sends.length, 0);
        await page.screenshot({ path: `${out}/${name}-configuration.png` });
        await dialog.getByRole('button', { name: '测试全部通知', exact: true }).click();
        await page.waitForFunction(() => document.body.textContent.includes('已收到通知'));
        await dialog.getByRole('button', { name: '测试全部通知', exact: true }).waitFor({ state: 'visible' });
        await page.waitForTimeout(100);
        assert.deepEqual(sends, types);
        assert.equal(maximumInFlight, 1);
        assert.equal(await dialog.getByText('已收到通知', { exact: false }).count(), 9);
        await dialog.locator('button').filter({ hasText: /^transaction ·/ }).click();
        await dialog.getByText('PRESERVED-UNKNOWN-FIELD', { exact: false }).waitFor();
        assert.ok(await dialog.getByText('-0.00000001', { exact: true }).count());
        assert.ok(await dialog.getByText('123456789012.12345678', { exact: true }).count());
        assert.ok(await dialog.getByText('未提供', { exact: true }).count());
        const before = sends.length;
        await dialog.getByRole('button', { name: '刷新', exact: true }).click();
        await page.waitForTimeout(100);
        assert.equal(sends.length, before);
        await dialog.getByRole('button', { name: '2', exact: true }).click();
        await dialog.getByRole('button', { name: '2', exact: true }).waitFor();
        assert.ok(recordReads >= 3);
        await dialog.getByRole('button', { name: '1', exact: true }).click();
        await dialog.locator('button').filter({ hasText: /^transaction ·/ }).click();
        await dialog.getByText('PRESERVED-UNKNOWN-FIELD', { exact: false }).waitFor();
        await dialog.locator('dl').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${out}/${name}-1366.png` });
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.screenshot({ path: `${out}/${name}-${width}.png` });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${name}: horizontal overflow at ${width}`);
        }
        assert.equal(accountChecks, 0);
        await dialog.getByRole('button', { name: '检查 U1 账户并同步卡组', exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        await page.getByRole('row').filter({ hasText: 'U1 synthetic fixture' }).getByRole('button', { name: '编辑', exact: true }).click();
        await page.getByText('GROUP-SHARE', { exact: false }).waitFor();
        assert.equal(accountChecks, 1);
        assert.equal(await page.getByLabel('apiKey', { exact: true }).inputValue(), '');
        assert.equal(await page.getByText('通知测试模式', { exact: true }).count(), 0);
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpected, []);
        console.log(`${name}: create/configuration, 9 sequential notifications, field precision, masking, pagination and responsive UI passed`);
    } finally { await browser.close(); }
}
