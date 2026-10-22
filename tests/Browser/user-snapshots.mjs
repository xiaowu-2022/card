import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Entire browser session is synthetic; no application/provider requests escape.
const origin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const company = { id: '11111111-1111-4111-8111-111111111111', name: '快照测试公司' };
const user = { id: '22222222-2222-4222-8222-222222222222', companyId: company.id, companyName: company.name,
    accountId: '202610131234', displayName: '快照客户', email: 'snapshot@example.test', status: 'ACTIVE',
    createdAt: '2026-10-13T00:00:00Z', lastLoginAt: null, ordinaryMember: false, promotionRank: 0,
    availableBalance: '0.00000000', securityDeposit: '0.00000000', descendantCount: 1, supportAgent: false, remark: null, wallets: [], receiptTotals: [],
    operationRestrictions: {}, financialSummary: { estimate: '12.00000000', cardBalance: '12.00000000', transfers: { incoming: [], outgoing: [] } } };
const shared = { auth: { admin: { id: 'admin', name: '管理员', email: 'admin@example.test', scope: 'PLATFORM',
    permissions: ['users.read', 'wallet.read', 'ledger.read', 'cards.read'] } }, locale: 'zh-CN', adminLocale: 'zh-CN',
    tenant: null, branding: null, errors: {}, flash: {}, publicAssets: [], requestId: 'fixture', i18n: { locale: 'zh-CN', enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Shanghai', surface: 'platform' } };
const access = { financialAccess: { balances: true, receipts: false, commission: false, withdrawals: false, funds: true },
    canManageRestrictions: true, canRemark: false, canManagePartners: false, canCreateUser: true,
    canCleanup: true, canManageSupport: false, canAdjustWallet: true, canChangeInvitation: false,
    canChangeReferrer: false, canAdjustCommission: false, canViewKyc: false, canViewFunds: true, canViewTopups: false };
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
    const requests = [], errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.route('**/*', async (route) => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/build/')) return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
        assert.equal(request.method(), 'GET', 'Historical mode must not issue writes');
        const at = url.searchParams.get('as_of');
        requests.push(url);
        if (url.pathname.endsWith('/referrer-candidates')) return route.fulfill({ json: { items: [], selected: null, hasMore: false } });
        if (url.pathname.endsWith('/funds-summary')) return route.fulfill({ json: { items: [], estimate: '12.00000000', readAt: at, updatedAt: null } });
        if (url.pathname.endsWith('/cards-summary')) return route.fulfill({ json: { items: [], totals: { funded: '0', returned: '0', balance: '0', consumed: '0' }, page: 1, total: 0, readAt: at } });
        const historicalAccess = at ? Object.fromEntries(Object.entries(access).map(([k, v]) => [k, k.startsWith('can') && k !== 'canManageRestrictions' ? false : v])) : access;
        if (url.pathname.endsWith('/details')) return route.fulfill({ json: { user, ...historicalAccess, readOnly: Boolean(at), snapshotAt: at } });
        if (url.pathname === '/platform/users/export') return route.fulfill({ contentType: 'text/csv; charset=UTF-8', headers: { 'Content-Disposition': 'attachment; filename="users-fixture.csv"' }, body: '\uFEFF公司,账号\r\n快照测试公司,202610131234\r\n' });
        assert.equal(url.pathname, '/platform/users');
        const current = Number(url.searchParams.get('page') ?? 1);
        const filters = Object.fromEntries(url.searchParams);
        const props = { ...shared, ...historicalAccess, companies: [company], filters, readOnly: Boolean(at), snapshotAt: at,
            snapshotEarliest: '2026-10-13T00:00:00', users: { data: [user], total: 40, current_page: current, last_page: 2,
                prev_page_url: current === 2 ? '/platform/users?' + new URLSearchParams({ ...filters, page: '1' }) : null,
                next_page_url: current === 1 ? '/platform/users?' + new URLSearchParams({ ...filters, page: '2' }) : null } };
        const state = { component: 'platform/Users', props, url: url.pathname + url.search, version: 'fixture' };
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    });
    await page.goto(origin + '/platform/users?page=2');
    // A plain search must remain a live query; historical mode is explicit.
    assert.equal(await page.getByLabel('截止时间（北京时间）').inputValue(), '');
    await page.getByRole('textbox', { name: '搜索姓名、账号 ID 或邮箱' }).fill('snapshot-user');
    await page.getByRole('button', { name: '应用', exact: true }).click();
    await page.waitForURL('**search=snapshot-user');
    assert.equal(new URL(page.url()).searchParams.has('as_of'), false);
    assert.equal(new URL(page.url()).searchParams.has('page'), false);
    await page.getByLabel('截止时间（北京时间）').fill('2026-10-13T08:00:01');
    await page.getByRole('button', { name: '应用', exact: true }).click();
    await page.waitForURL('**as_of=*');
    assert.equal(new URL(page.url()).searchParams.get('page'), null);
    assert.equal(new URL(page.url()).searchParams.get('as_of'), '2026-10-13T08:00:01');
    assert.equal(await page.getByRole('button', { name: '添加账号', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: '清理数据', exact: true }).count(), 0);
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: '导出表格', exact: true }).click();
    assert.equal((await download).suggestedFilename(), 'users-fixture.csv');
    const exported = requests.findLast((u) => u.pathname === '/platform/users/export');
    assert.equal(exported.searchParams.get('as_of'), '2026-10-13T08:00:01');
    assert.equal(exported.searchParams.get('page'), null);
    await page.getByRole('button', { name: '详情', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('heading', { name: '账号信息', exact: true }).waitFor().catch(async (error) => { console.error(await page.locator('body').innerText(), errors); throw error; });
    assert.equal(requests.findLast((u) => u.pathname.endsWith('/details')).searchParams.get('as_of'), '2026-10-13T08:00:01');
    assert.equal(await dialog.getByRole('button', { name: '操作限制', exact: true }).count(), 0);
    assert.equal(await dialog.getByRole('link').count(), 0);
    await dialog.getByRole('button', { name: '关闭', exact: true }).click();
    const fundsRead = page.waitForResponse('**/funds-summary?as_of=*');
    await page.getByRole('button', { name: /12/ }).first().click();
    await page.getByRole('dialog').waitFor();
    await fundsRead;
    assert.equal(requests.findLast((u) => u.pathname.endsWith('/funds-summary')).searchParams.get('as_of'), '2026-10-13T08:00:01');
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: '重置筛选', exact: true }).click();
    await page.waitForURL(origin + '/platform/users');
    assert.equal(await page.getByLabel('截止时间（北京时间）').inputValue(), '');
    assert.deepEqual(errors, []);
    console.log('User snapshot browser checks passed.');
} finally {
    await browser.close();
}
