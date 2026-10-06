import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5239';
const adminOrigin = 'http://admin.localhost:8000';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const out = 'artifacts/uni-parity/support-bot';
mkdirSync(out, { recursive: true });
const company = { id: '11111111-1111-4111-8111-111111111111', name: 'Offline company' };
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
        });
        const page = await context.newPage();
        const errors = [],
            transfers = [];
        let fail = true;
        let chat = {
            id: 'offline',
            mode: 'BOT',
            revision: 3,
            botEnabled: true,
            olderCursor: null,
            messages: [
                {
                    id: 'reply',
                    sequence: 2,
                    fromSupport: true,
                    senderKind: 'BOT',
                    supportName: '客服助手',
                    text: '请先完成实名认证。',
                    createdAt: '2026-10-06T12:00:00Z',
                    imageUrl: null,
                },
            ],
        };
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('**/*', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                const key = url.pathname.slice(7);
                if (key === '/bootstrap')
                    return route.fulfill({ json: { ...fixture.authenticated, locale: 'zh-CN' } });
                if (key === '/support' && req.method() === 'GET')
                    return route.fulfill({ json: chat });
                if (key === '/support/handoff') {
                    transfers.push(req.postDataJSON());
                    if (fail) {
                        fail = false;
                        return route.fulfill({ status: 503, json: {} });
                    }
                    chat = { ...chat, mode: 'WAITING', revision: 4 };
                    return route.fulfill({ status: 204 });
                }
                if (key === '/support/read' || key === '/wallet/ensure')
                    return route.fulfill({ status: 204 });
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                throw new Error('Unexpected API: ' + req.method() + key);
            }
            if (url.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/#/pages/support/index');
        const button = page.locator('uni-button').filter({ hasText: /^转人工$/ });
        await button.waitFor();
        await page.getByText('客服助手', { exact: true }).waitFor();
        await page.screenshot({ path: `${out}/${name}-bot.png` });
        await button.click();
        await page.getByText('转人工失败，请重试。').waitFor();
        await button.click();
        await page.getByText('等待人工接待', { exact: true }).waitFor();
        assert.equal(transfers.length, 2);
        assert.equal(transfers[0].request_id, transfers[1].request_id);
        assert.equal(await button.count(), 0);
        assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            false,
        );
        assert.deepEqual(errors, []);
        await page.screenshot({ path: `${out}/${name}-handoff.png` });
        await context.close();

        const desktop = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const admin = await desktop.newPage();
        const reads = [],
            writes = [],
            adminErrors = [];
        let saved = false;
        admin.on('pageerror', (e) => adminErrors.push(e.message));
        const shared = {
            errors: {},
            publicAssets: [],
            tenant: null,
            auth: {
                admin: {
                    name: 'Offline Admin',
                    permissions: ['support.read', 'support.send', 'support.bot.manage'],
                },
                user: null,
            },
            flash: {},
            i18n: {
                locale: 'en',
                enabledLocales: ['en', 'zh-CN'],
                timezone: 'Asia/Shanghai',
                surface: 'platform',
            },
            requestId: 'fixture',
        };
        await desktop.route('**/*', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            assert.equal(url.origin, adminOrigin);
            if (url.pathname.startsWith('/build/'))
                return route.fulfill({
                    body: readFileSync('public' + url.pathname),
                    contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript',
                });
            if (url.pathname.includes('/faqs/')) {
                reads.push(url.href);
                return route.fulfill({
                    json: {
                        id: null,
                        overrides_id: 'global-faq',
                        question: '如何开卡？',
                        variants: [],
                        keywords: ['开卡'],
                        answer: '公共答案',
                        enabled: true,
                        archived: false,
                        revision: 0,
                    },
                });
            }
            if (url.pathname.endsWith('/faqs') && req.method() === 'POST') {
                writes.push(req.postDataJSON());
                saved = true;
                return route.fulfill({ json: { id: 'override' } });
            }
            if (url.pathname.endsWith('/preview'))
                return route.fulfill({
                    json: {
                        answer: { answer: '公司专属答案' },
                        candidates: [{ id: 'override', question: '如何开卡？', score: 1 }],
                    },
                });
            assert.equal(url.pathname, '/platform/support/bot');
            const data = [
                {
                    id: 'global-faq',
                    question: '如何开卡？',
                    scope: 'public',
                    overridden: saved,
                    enabled: true,
                    archived: false,
                },
            ];
            const state = {
                component: 'platform/SupportBot',
                props: {
                    ...shared,
                    companies: [company],
                    settings: { enabled: true, revision: 1 },
                    filters: Object.fromEntries(url.searchParams),
                    faqs: {
                        data,
                        total: 1,
                        current_page: 1,
                        last_page: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                },
                url: url.pathname + url.search,
                version: 'fixture',
            };
            if (req.headers()['x-inertia'])
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
            return route.fulfill({
                contentType: 'text/html',
                body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
            });
        });
        await admin.goto(adminOrigin + '/platform/support/bot?company=' + company.id);
        await admin.getByRole('button', { name: 'Customize', exact: true }).waitFor();
        assert.equal(reads.length, 0);
        await admin.getByRole('button', { name: 'Customize', exact: true }).click();
        const dialog = admin.getByRole('dialog');
        await dialog.waitFor({ timeout: 8000 }).catch(async (e) => {
            console.log({ reads, adminErrors, body: await admin.locator('body').innerText() });
            await admin.screenshot({ path: `${out}/${name}-faq-failed.png` });
            throw e;
        });
        await dialog
            .getByLabel('Answer (Chinese)', { exact: true })
            .fill('公司专属答案', { timeout: 4000 });
        assert.equal(await dialog.getByLabel('Question', { exact: true }).isDisabled(), true);
        admin.once('dialog', (d) => d.dismiss());
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        assert.equal(await dialog.isVisible(), true);
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(writes.length, 1);
        assert.equal(writes[0].company, company.id);
        assert.equal(writes[0].overrides_id, 'global-faq');
        await admin.getByRole('textbox', { name: 'Search questions' }).fill('开卡');
        await admin.getByRole('button', { name: 'Search', exact: true }).click();
        await admin.waitForURL(/search=/);
        assert.equal(new URL(admin.url()).searchParams.get('company'), company.id);
        await admin.getByRole('textbox', { name: 'Customer question' }).fill('开卡');
        await admin.getByRole('button', { name: 'Test match', exact: true }).click();
        await admin.getByText('公司专属答案', { exact: true }).waitFor();
        assert.equal(
            await admin.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            false,
        );
        assert.deepEqual(adminErrors, []);
        await admin.screenshot({ path: `${out}/${name}-faq.png` });
        let endAttempts = [],
            threadRevision = 4,
            threadMode = 'WAITING';
        await desktop.route('**/platform/tenants/**/support/users/**', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            if (url.pathname.endsWith('/finish')) {
                endAttempts.push(req.postDataJSON());
                if (endAttempts.length === 1) return route.fulfill({ status: 503, json: {} });
                if (endAttempts.length === 2) {
                    threadRevision = 5;
                    return route.fulfill({ status: 409, json: {} });
                }
                threadMode = 'BOT';
                threadRevision = 6;
                return route.fulfill({ status: 204 });
            }
            const state = {
                component: 'platform/Support',
                props: {
                    ...shared,
                    companies: [company],
                    supportName: null,
                    filters: {},
                    inbox: {
                        data: [],
                        total: 0,
                        current_page: 1,
                        last_page: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                    chat: {
                        ...chat,
                        id: 'conversation',
                        mode: threadMode,
                        revision: threadRevision,
                        before: 0,
                        company: company.name,
                        tenantId: company.id,
                        userId: 'customer',
                        accountId: '202600000001',
                        email: 'fixture@example.test',
                    },
                },
                url: url.pathname,
                version: 'fixture',
            };
            if (req.headers()['x-inertia'])
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
            return route.fulfill({
                contentType: 'text/html',
                body: `<!doctype html><html><head><meta charset="utf-8">${(entry.css ?? []).map((f) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
            });
        });
        await admin.goto(`${adminOrigin}/platform/tenants/${company.id}/support/users/customer`);
        const finish = admin.getByRole('button', { name: 'End service', exact: true });
        await finish.click();
        await admin.getByText('Unable to save. Please try again.', { exact: true }).waitFor();
        await finish.click();
        await admin
            .getByText(
                'Conversation changed. Refresh and read the latest messages before ending service.',
                { exact: true },
            )
            .waitFor();
        await finish.click();
        await admin.locator('.support-thread').getByText('Bot support', { exact: true }).waitFor();
        assert.equal(endAttempts.length, 3);
        assert.equal(endAttempts[0].request_id, endAttempts[1].request_id);
        assert.notEqual(endAttempts[1].request_id, endAttempts[2].request_id);
        assert.equal(endAttempts[2].revision, 5);
        assert.deepEqual(adminErrors, []);
        await admin.screenshot({ path: `${out}/${name}-end-service.png` });
        await desktop.close();
        console.log(
            `${name}: handoff retry, bot identity, waiting state, scoped lazy FAQ edit, dirty guard, save, search scope and match preview passed`,
        );
    } finally {
        await browser.close();
    }
}
