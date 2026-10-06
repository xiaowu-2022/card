import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const writes = [], errors = [];
    let canCreate = true;
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            writes.push({ path: url.pathname, data: request.postDataJSON() });
        }
        const state = { component: 'platform/Users', url: '/platform/users', version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['users.read', ...(canCreate ? ['users.create'] : [])] }, user: null },
            i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN'], timezone: 'UTC', surface: 'platform' },
            users: { data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
            companies: [{ id: company, name: '测试公司' }], filters: { company },
            financialAccess: { balances: false, commission: false, withdrawals: false }, canCreateUser: canCreate,
        } };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + '/platform/users');
    await page.getByRole('button', { name: '添加账号', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    assert.equal(await dialog.locator('select').inputValue(), company);
    await dialog.locator('input[type=email]').fill('created@example.test');
    await dialog.locator('input[type=text]').fill('新账号');
    await dialog.locator('input[type=password]').nth(0).fill('secret123');
    await dialog.locator('input[type=password]').nth(1).fill('secret123');
    mkdirSync('artifacts/platform-user-creation', { recursive: true });
    await page.screenshot({ path: 'artifacts/platform-user-creation/dialog.png' });
    page.once('dialog', (prompt) => prompt.dismiss());
    await dialog.getByRole('button', { name: '取消', exact: true }).click();
    assert.equal(await dialog.count(), 1);
    await dialog.locator('button[type=submit]').click();
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(writes.length, 1);
    assert.equal(writes[0].path, `/platform/tenants/${company}/users`);
    assert.equal(writes[0].data.email, 'created@example.test');
    assert.match(writes[0].data.request_id, /^[a-f0-9-]{36}$/);
    canCreate = false;
    await page.reload();
    assert.equal(await page.getByRole('button', { name: '添加账号', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: creation dialog, company selection, dirty guard, explicit submit, request UUID and permission visibility (offline fixtures).');
} finally { await browser.close(); }
