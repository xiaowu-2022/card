import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const user = '33333333-3333-4333-8333-333333333333';
const editorPath = `/platform/tenants/${company}/users/${user}/invitation-code`;
const listPath = `/platform/users?company=${company}&page=2`;
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const writes = [], errors = [], reads = [];
    let permitted = true, current = '523613', revision = 0;
    page.on('pageerror', (e) => errors.push(e.message));
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (request.method() === 'POST') {
            assert.equal(url.pathname, editorPath);
            const body = request.postData();
            const field = (name) => body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`))?.[1];
            writes.push({ code: field('new_code'), old: field('old_code'), confirmed: field('confirmed'), id: field('request_id') });
            current = field('new_code'); revision++;
            return route.fulfill({ json: { saved: true } });
        }
        const shared = {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['users.read', ...(permitted ? ['users.invitation.manage'] : [])] }, user: null },
            i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN'], timezone: 'UTC', surface: 'platform' },
        };
        const empty = { data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null };
        let state;
        if (url.pathname === editorPath) {
            reads.push(url.pathname);
            assert.equal(request.headers()['x-admin-dialog'], '1');
            state = { component: 'platform/UserInvitationCode', url: editorPath, version: 'fixture', props: {
                ...shared, account: { id: user, companyId: company, companyName: '测试公司', accountId: '100000001', email: 'member@example.test' },
                currentCode: current, revision, nextCode: 523614, canChange: true,
                history: { ...empty, data: revision ? [{ id: 'change', old_code: '523613', new_code: current, reason: '测试修改', actor_name: 'Owner', created_at: '2026-10-06T00:00:00Z' }] : [], total: revision },
            } };
        } else {
            state = { component: 'platform/Users', url: listPath, version: 'fixture', props: {
                ...shared, users: { ...empty, data: [{ id: user, companyName: '测试公司', companyId: company, promotionRank: 0, ordinaryMember: false, accountId: '100000001', displayName: 'Test member', email: 'member@example.test', status: 'ACTIVE', createdAt: '2026-10-06T00:00:00Z', lastLoginAt: null }], total: 2, current_page: 2, last_page: 2 },
                companies: [{ id: company, name: '测试公司' }], filters: { company }, financialAccess: { balances: false, commission: false, withdrawals: false }, canChangeInvitation: permitted,
            } };
        }
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + listPath);
    assert.equal(reads.length, 0);
    await page.getByRole('button', { name: '更多操作' }).click();
    await page.getByRole('menuitem', { name: '修改邀请码' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('新邀请码', { exact: true }).waitFor();
    await dialog.getByLabel('新邀请码', { exact: true }).fill('523700');
    await dialog.getByLabel('调整原因', { exact: true }).fill('测试修改');
    assert.equal(writes.length, 0);
    await dialog.locator('input[type=checkbox]').check();
    mkdirSync('artifacts/platform-invitation-code', { recursive: true });
    await page.screenshot({ path: 'artifacts/platform-invitation-code/dialog.png' });
    page.once('dialog', (prompt) => prompt.dismiss());
    await page.keyboard.press('Escape');
    assert.equal(await dialog.count(), 1);
    await dialog.getByRole('button', { name: '确认修改', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    await page.screenshot({ path: 'artifacts/platform-invitation-code/saved.png' });
    assert.equal(writes.length, 1);
    assert.deepEqual({ ...writes[0], id: null }, { code: '523700', old: '523613', confirmed: '1', id: null });
    assert.match(writes[0].id, /^[a-f0-9-]{36}$/);
    assert.equal(new URL(page.url()).searchParams.get('company'), company);
    assert.equal(new URL(page.url()).searchParams.get('page'), '2');
    await page.getByRole('button', { name: '更多操作' }).click();
    await page.getByRole('menuitem', { name: '修改邀请码' }).click();
    await dialog.getByRole('cell', { name: '523700', exact: true }).waitFor();
    await page.keyboard.press('Escape');
    permitted = false;
    await page.goto(origin + listPath);
    assert.equal(await page.getByRole('button', { name: '更多操作' }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: lazy invitation editor, confirmation, dirty guard, submit, history, list URL preservation and permission visibility (offline).');
} finally { await browser.close(); }
