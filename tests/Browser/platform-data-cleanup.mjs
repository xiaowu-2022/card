import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const origin = 'http://admin.localhost:8000';
const company = '22222222-2222-4222-8222-222222222222';
const user = '33333333-3333-4333-8333-333333333333';
const batch = '44444444-4444-4444-8444-444444444444';
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        const writes = [],
            errors = [];
        let stale = true,
            blocked = false,
            completed = false,
            allowed = true;
        page.on('pageerror', (error) => errors.push(error.message));
        const preview = () => ({
            id: batch,
            status: completed ? 'COMPLETED' : 'PREVIEW',
            summary: {
                total: 5,
                categoryCounts: [
                    { category: 'Orders', count: 1, related: 0 },
                    { category: 'Funds and ledger data', count: 4, related: 4 },
                ],
                affectedAccounts: [{ id: user, accountId: '202601010001', deleted: blocked }],
                blockers: blocked
                    ? [{ code: 'CLEANUP_HAS_DESCENDANTS', accountId: '202601010001' }]
                    : [],
                balances: [
                    {
                        id: 'balance',
                        accountId: '202601010001',
                        asset: 'ETH',
                        accountType: 'USER_AVAILABLE',
                        before: '0.123456789123456789',
                        after: '0',
                        removed: false,
                    },
                ],
            },
        });
        await page.route('**/*', (route) => {
            const req = route.request(),
                url = new URL(req.url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname.startsWith('/build/'))
                return route.fulfill({
                    body: readFileSync('public' + url.pathname),
                    contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript',
                });
            if (url.pathname.includes('/data-cleanup')) {
                if (req.method() === 'POST')
                    writes.push({ path: url.pathname, data: req.postDataJSON() });
                if (url.pathname.endsWith('/accounts'))
                    return route.fulfill({
                        json: {
                            accounts: [
                                { id: user, accountId: '202601010001', displayName: '测试账号' },
                            ],
                        },
                    });
                if (url.pathname.endsWith('/records'))
                    return route.fulfill({
                        json: {
                            records: [
                                {
                                    recordId: user,
                                    category: 'Orders',
                                    accountId: '202601010001',
                                    asset: 'ETH',
                                    amount: '0.123456789123456789',
                                    related: true,
                                },
                            ],
                            page: 1,
                            lastPage: 1,
                        },
                    });
                if (url.pathname.endsWith('/execute')) {
                    if (stale)
                        return route.fulfill({
                            status: 409,
                            json: {
                                error: { message: 'Data changed after preview. Preview again.' },
                            },
                        });
                    completed = true;
                }
                return route.fulfill({ json: preview() });
            }
            const state = {
                component: 'platform/Users',
                url: '/platform/users',
                version: 'fixture',
                props: {
                    errors: {},
                    publicAssets: [],
                    tenant: null,
                    flash: {},
                    auth: {
                        admin: {
                            id: 'owner',
                            name: 'Owner',
                            email: 'owner@example.test',
                            scope: 'PLATFORM',
                            permissions: ['users.read', ...(allowed ? ['users.cleanup'] : [])],
                        },
                        user: null,
                    },
                    i18n: {
                        locale: 'zh-CN',
                        enabledLocales: ['zh-CN'],
                        timezone: 'UTC',
                        surface: 'platform',
                    },
                    users: {
                        data: completed
                            ? []
                            : [
                                  {
                                      id: user,
                                      companyId: company,
                                      companyName: '测试公司',
                                      displayName: '测试账号',
                                      accountId: '202601010001',
                                      email: 'user@example.test',
                                      status: 'ACTIVE',
                                      createdAt: '2026-10-09T00:00:00Z',
                                      promotionRank: 0,
                                      ordinaryMember: false,
                                      remark: null,
                                  },
                              ],
                        total: completed ? 0 : 1,
                        current_page: 1,
                        last_page: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                    companies: [{ id: company, name: '测试公司' }],
                    filters: { company },
                    financialAccess: { balances: false, commission: false, withdrawals: false },
                    canCleanup: allowed,
                },
            };
            if (req.headers()['x-inertia'])
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
            return route.fulfill({
                contentType: 'text/html',
                body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
            });
        });
        await page.goto(origin + '/platform/users');
        await page.getByRole('button', { name: '清理数据', exact: true }).last().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('订单', { exact: true }).check();
        assert.equal(writes.length, 0);
        await dialog.getByRole('button', { name: '预览清理', exact: true }).click();
        await dialog.getByText('待清理记录: 5').waitFor();
        assert.deepEqual(writes[0].data, { user_ids: [user], modules: ['orders'] });
        assert.equal(
            await dialog.getByRole('button', { name: '永久删除', exact: true }).isDisabled(),
            true,
        );
        await dialog.getByRole('button', { name: '查看完整清理清单', exact: true }).click();
        await dialog.getByRole('cell', { name: user, exact: true }).waitFor();
        await dialog
            .getByLabel('我确认永久删除本次预览中的全部记录，包括连带记录及所列余额变化。', {
                exact: true,
            })
            .check();
        await dialog.getByRole('button', { name: '永久删除', exact: true }).click();
        await dialog.getByText('预览后数据已变化，请重新预览。').waitFor();
        const firstRequest = writes[1].data.request_id;
        assert.deepEqual(Object.keys(writes[1].data).sort(), ['confirmed', 'request_id']);
        assert.equal(writes[1].data.confirmed, true);
        await dialog.getByRole('button', { name: '返回修改', exact: true }).click();
        await dialog.getByLabel('删除整个账号', { exact: true }).check();
        for (const label of ['订单', '佣金', '卡片']) {
            assert.equal(await dialog.getByLabel(label, { exact: true }).isChecked(), true);
            assert.equal(await dialog.getByLabel(label, { exact: true }).isDisabled(), true);
        }
        blocked = true;
        await dialog.getByRole('button', { name: '预览清理', exact: true }).click();
        await dialog.getByText('账号仍有下级，请先迁走下级。', { exact: false }).waitFor();
        await dialog
            .getByLabel('我确认永久删除本次预览中的全部记录，包括连带记录及所列余额变化。', {
                exact: true,
            })
            .check();
        assert.equal(
            await dialog.getByRole('button', { name: '永久删除', exact: true }).isDisabled(),
            true,
        );
        await dialog.getByRole('button', { name: '返回修改', exact: true }).click();
        blocked = false;
        stale = false;
        await dialog.getByRole('button', { name: '预览清理', exact: true }).click();
        await dialog
            .getByLabel('我确认永久删除本次预览中的全部记录，包括连带记录及所列余额变化。', {
                exact: true,
            })
            .check();
        mkdirSync('artifacts/platform-data-cleanup', { recursive: true });
        await page.screenshot({ path: `artifacts/platform-data-cleanup/preview-${width}.png` });
        assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            false,
        );
        await dialog.getByRole('button', { name: '永久删除', exact: true }).click();
        await dialog.getByText('清理完成。', { exact: true }).waitFor();
        assert.notEqual(writes.at(-1).data.request_id, firstRequest);
        allowed = false;
        await page.reload();
        assert.equal(await page.getByRole('button', { name: '清理数据', exact: true }).count(), 0);
        assert.deepEqual(errors, []);
        await page.close();
    }
    console.log(
        'PASS: desktop/mobile cleanup, exact selection, confirmation, dependent modules, stale recovery, blockers, record list, result refresh and permission visibility',
    );
} finally {
    await browser.close();
}
