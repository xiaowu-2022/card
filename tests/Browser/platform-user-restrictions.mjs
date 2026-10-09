import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222', user = '33333333-3333-4333-8333-333333333333';
const width = Number(process.env.RESTRICTIONS_VIEWPORT_WIDTH ?? 1440);
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const page = await browser.newPage({ viewport: { width, height: 1000 } });
    const writes = [], errors = [];
    let allowed = true, stale = false;
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => {
        const req = route.request(), url = new URL(req.url());
        if (url.origin !== origin) return route.abort();
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (url.pathname.endsWith('/restrictions')) {
            if (req.method() === 'POST') { writes.push(req.postDataJSON()); return route.fulfill({ status: stale ? 409 : 200, json: {} }); }
            return route.fulfill({ json: { withdrawal_blocked: true, deposit_refund_blocked: false, card_transfer_blocked: false, wallet_transfer_blocked: false, revision: 3 } });
        }
        const state = { component: 'platform/Users', url: '/platform/users', version: 'fixture', props: {
            errors: {}, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { id: 'owner', name: 'Owner', email: 'owner@example.test', scope: 'PLATFORM', permissions: ['users.read', ...(allowed ? ['users.restrictions.manage'] : [])] }, user: null },
            i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN'], timezone: 'UTC', surface: 'platform' },
            users: { data: [{ id: user, companyId: company, companyName: '测试公司', accountId: '202601010001', email: 'user@example.test', status: 'ACTIVE', createdAt: '2026-10-09T00:00:00Z', promotionRank: 0, ordinaryMember: false, remark: null }], total: 1, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
            companies: [{ id: company, name: '测试公司' }], filters: { company },
            financialAccess: { balances: false, commission: false, withdrawals: false }, canManageRestrictions: allowed,
        } };
        if (req.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map(f => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + '/platform/users');
    const open = async () => { await page.getByRole('button', { name: '更多操作', exact: true }).click(); await page.getByRole('menuitem', { name: '操作限制' }).click(); };
    await open();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('禁止提现', { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('禁止提现', { exact: true }).isChecked(), true);
    assert.equal(writes.length, 0);
    await dialog.getByLabel('禁止申请退保', { exact: true }).check();
    assert.equal(await dialog.getByRole('button', { name: '保存', exact: true }).isDisabled(), true);
    page.once('dialog', d => d.dismiss());
    await dialog.getByRole('button', { name: '取消', exact: true }).click();
    assert.equal(await dialog.count(), 1);
    await dialog.getByLabel('我确认以上设置。', { exact: true }).check();
    mkdirSync('artifacts/platform-user-restrictions', { recursive: true });
    await page.screenshot({ path: `artifacts/platform-user-restrictions/dialog-${width}.png` });
    await dialog.getByRole('button', { name: '保存', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(writes.length, 1);
    assert.equal(writes[0].revision, 3);
    assert.equal(writes[0].withdrawal_blocked, true);
    assert.equal(writes[0].deposit_refund_blocked, true);
    assert.equal(writes[0].card_transfer_blocked, false);
    assert.equal(writes[0].wallet_transfer_blocked, false);
    assert.equal(writes[0].confirmed, true);
    assert.match(writes[0].request_id, /^[a-f0-9-]{36}$/);
    stale = true;
    await open();
    await dialog.getByLabel('禁止账户互转', { exact: true }).check();
    await dialog.getByLabel('我确认以上设置。', { exact: true }).check();
    await dialog.getByRole('button', { name: '保存', exact: true }).click();
    await page.getByText('设置已被修改，请关闭后重新打开以获取最新设置。').waitFor();
    assert.equal(await dialog.getByLabel('禁止账户互转', { exact: true }).isChecked(), true);
    allowed = false;
    await page.reload();
    assert.equal(await page.getByRole('button', { name: '更多操作', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: independent restriction settings, lazy read, confirmation, dirty guard, scoped save, stale feedback, permission visibility');
} finally { await browser.close(); }
