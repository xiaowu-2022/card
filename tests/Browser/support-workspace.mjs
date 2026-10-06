import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const h5 = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5239',
    admin = 'http://admin.localhost:8000';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const out = 'artifacts/uni-parity/support-user';
mkdirSync(out, { recursive: true });
const conversation = '11111111-1111-4111-8111-111111111111',
    company = '22222222-2222-4222-8222-222222222222',
    user = '33333333-3333-4333-8333-333333333333';
const paginate = (data) => ({
    data,
    total: data.length,
    current_page: 1,
    last_page: 1,
    prev_page_url: null,
    next_page_url: null,
});
for (const [name, engine, options] of [
    ['chrome', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            }),
            page = await context.newPage();
        page.setDefaultTimeout(12000);
        const button = (text) =>
            page.locator('uni-button:visible').filter({ hasText: new RegExp('^' + text + '$') });
        const errors = [],
            writes = [];
        let enabled = true,
            profile = { support_name: '小林', revision: 1 },
            revision = 3;
        let replies = [
            {
                id: user,
                title: '问候语',
                body: '您好，请问有什么可以帮您？',
                revision: 1,
                scope: 'shared',
            },
        ];
        const chat = () => ({
            id: conversation,
            customerName: '测试客户',
            customerOnline: true,
            mode: 'HUMAN',
            revision,
            botEnabled: true,
            humanSupport: { available: false, timezone: 'Asia/Kuala_Lumpur', nextOpenAt: null },
            messages: [
                {
                    id: 'message',
                    sequence: 1,
                    fromSupport: false,
                    senderKind: 'USER',
                    text: '客户咨询',
                    createdAt: '2026-10-06T12:00:00Z',
                    imageUrl: null,
                },
            ],
            olderCursor: null,
        });
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('**/*', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            if (url.origin !== h5) return route.abort();
            if (!url.pathname.startsWith('/api/v1')) return route.continue();
            const path = url.pathname.slice(7);
            if (path.startsWith('/support-workspace') && !enabled)
                return route.fulfill({ status: 403, json: { message: 'Forbidden' } });
            if (req.method() === 'POST') {
                const data = req.postDataJSON();
                writes.push({ path, data });
                if (path === '/support-workspace/profile')
                    profile = { support_name: data.support_name, revision: profile.revision + 1 };
                if (path === '/support-workspace/replies')
                    replies = data.archived
                        ? replies.filter((r) => r.id !== data.id)
                        : [
                              ...replies.filter((r) => r.id !== data.id),
                              { ...data, revision: data.revision + 1, scope: 'personal' },
                          ];
                if (path.endsWith('/finish')) revision++;
                return route.fulfill({ status: 204 });
            }
            if (path === '/bootstrap')
                return route.fulfill({ json: { ...fixture.authenticated, locale: 'zh-CN' } });
            if (path === '/account')
                return route.fulfill({
                    json: {
                        supportAgent: enabled,
                        kycStatus: 'APPROVED',
                        promotionRank: 0,
                        accountQualified: true,
                    },
                });
            if (path === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (path === '/support-workspace')
                return route.fulfill({
                    json: {
                        profile,
                        awaitingCount: 1,
                        pendingMessageCount: 1,
                        inbox: paginate([
                            {
                                id: conversation,
                                accountId: '202600000001',
                                name: '测试客户',
                                mode: 'WAITING',
                                updatedAt: '2026-10-06T12:00:00Z',
                            },
                        ]),
                    },
                });
            if (path === '/support-workspace/replies')
                return route.fulfill({
                    json: paginate(
                        url.searchParams.get('personal') === '1'
                            ? replies.filter((r) => r.scope === 'personal')
                            : replies,
                    ),
                });
            if (path === '/support-workspace/conversations/' + conversation || path === '/support')
                return route.fulfill({ json: chat() });
            throw Error('Unexpected API ' + path);
        });
        await page.goto(h5 + '/#/pages/account/index');
        await button('客服工作台').click();
        await page.getByText('测试客户', { exact: true }).waitFor();
        await page.screenshot({ path: `${out}/${name}-inbox.png` });
        await page.getByRole('button', { name: '设置', exact: true }).click();
        await button('修改客服名 ›').click();
        await page.getByRole('dialog', { name: '修改客服名' }).getByRole('textbox', { name: '客服名称' }).fill('小王');
        await button('保存').click();
        await page.waitForTimeout(150);
        assert.ok(
            writes.some((w) => w.path.endsWith('/profile') && w.data.support_name === '小王'),
        );
        await page.locator('.conversation:visible').filter({ hasText: '测试客户' }).click();
        await page.getByText('客户咨询', { exact: true }).waitFor();
        assert.equal(
            writes.some((w) => w.path === '/support/read'),
            false,
            'Workspace must not acknowledge customer unread',
        );
        const composer = page.locator('.support-composer textarea');
        await composer.fill('开头结尾');
        await composer.evaluate((n) => {
            n.focus();
            n.setSelectionRange(2, 2);
        });
        await button('快捷回复').click();
        await page.locator('.reply-item:visible').filter({ hasText: '问候语' }).click();
        await page.waitForFunction(
            () =>
                document.querySelector('.support-composer textarea')?.value ===
                '开头您好，请问有什么可以帮您？结尾',
        );
        assert.equal(await composer.inputValue(), '开头您好，请问有什么可以帮您？结尾');
        assert.equal(
            writes.some((w) => w.path.endsWith('/messages')),
            false,
        );
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${out}/${name}-chat.png` });
        await page.getByText('Send message', { exact: true }).count();
        const send = page.locator('.support-composer-actions uni-button').last();
        await send.click();
        await page.waitForFunction(
            () => document.querySelector('.support-composer textarea')?.value === '',
        );
        assert.equal(writes.filter((w) => w.path.endsWith('/messages')).length, 1);
        assert.ok(writes.find((w) => w.path.endsWith('/messages')).path.includes(conversation));
        assert.equal(await button('结束接待').count(), 0);
        await page.waitForTimeout(100);
        assert.equal(writes.some((w) => w.path.endsWith('/finish')), false);
        await page.goto(h5 + '/#/pages/support-workspace/replies');
        await button('添加语录').click();
        const editor = page.locator('.editor');
        await editor.locator('input:visible').fill('自己的问候');
        await editor.locator('textarea').fill('人工快捷回复');
        await editor
            .locator('uni-button')
            .filter({ hasText: /^保存$/ })
            .click();
        await page.getByText('自己的问候', { exact: true }).waitFor();
        assert.ok(writes.some((w) => w.path === '/support-workspace/replies'));
        enabled = false;
        await page.goto(h5 + '/#/pages/support-workspace/index');
        await page.waitForURL(/pages\/account\/index/);
        assert.equal(await page.getByText('客服工作台', { exact: true }).count(), 0);
        assert.deepEqual(errors, []);
        await context.close();
        const desktop = await browser.newContext({ viewport: { width: 1366, height: 900 } }),
            adminPage = await desktop.newPage();
        let granted = false;
        const adminWrites = [];
        await desktop.route('**/*', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            assert.equal(url.origin, admin);
            if (url.pathname.startsWith('/build/'))
                return route.fulfill({
                    body: readFileSync('public' + url.pathname),
                    contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript',
                });
            if (req.method() === 'POST') {
                adminWrites.push(req.postDataJSON());
                granted = req.postDataJSON().enabled;
                return route.fulfill({ status: 204 });
            }
            const props = {
                errors: {},
                publicAssets: [],
                tenant: null,
                auth: {
                    admin: {
                        id: 'owner',
                        name: 'Fixture Owner',
                        email: 'owner@example.test',
                        scope: 'PLATFORM',
                        permissions: ['users.read', 'support.read', 'support.agents.manage'],
                    },
                    user: null,
                },
                flash: {},
                i18n: {
                    locale: 'en',
                    enabledLocales: ['en'],
                    timezone: 'UTC',
                    surface: 'platform',
                },
                users: paginate([
                    {
                        id: user,
                        companyId: company,
                        companyName: 'Company',
                        accountId: '202600000001',
                        displayName: 'Test user',
                        email: 'user@example.test',
                        status: 'ACTIVE',
                        supportAgent: granted,
                        supportRevision: granted ? 1 : 0,
                        promotionRank: 0,
                        ordinaryMember: false,
                        createdAt: '2026-10-06',
                        lastLoginAt: null,
                    },
                ]),
                companies: [{ id: company, name: 'Company' }],
                filters: {},
                financialAccess: { balances: false, commission: false, withdrawals: false },
                canManageSupport: true,
                canViewKyc: false,
                canViewFunds: false,
                canViewTopups: false,
                canAdjustWallet: false,
                canChangeReferrer: false,
                canAdjustCommission: false,
            };
            const state = {
                component: 'platform/Users',
                props,
                url: '/platform/users',
                version: 'fixture',
            };
            if (req.headers()['x-inertia'])
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
            return route.fulfill({
                contentType: 'text/html',
                body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
            });
        });
        adminPage.on('pageerror', (e) => errors.push(e.message));
        await adminPage.goto(admin + '/platform/users');

        await adminPage.getByRole('button', { name: 'More actions', exact: true }).click();
        await adminPage.getByRole('menuitem', { name: 'Make support agent', exact: true }).click();
        await adminPage.getByRole('button', { name: 'More actions', exact: true }).click();
        await adminPage
            .getByRole('menuitem', { name: 'Remove support access', exact: true })
            .waitFor();
        assert.deepEqual(adminWrites, [{ enabled: true, revision: 0 }]);
        await adminPage.screenshot({ path: `${out}/${name}-users.png` });
        assert.deepEqual(errors, []);
        await desktop.close();
        console.log(
            `${name}: user grant, My account entry, shared inbox, nickname, caret insertion, explicit send, compact header, personal replies and revoked access passed`,
        );
    } finally {
        await browser.close();
    }
}
