import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

// All page data, API responses and mutations below are in-memory fixtures. No server/provider access.
const origin = 'http://admin.localhost:8000',
    out = 'artifacts/uni-parity/admin-card-sync';
mkdirSync(out, { recursive: true });
const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
const entry = manifest['resources/js/app.tsx'];
const companies = [{ id: '11111111-1111-4111-8111-111111111111', name: '测试公司' }];
const cards = ['22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'].map(
    (id, index) => ({
        id,
        tenantId: companies[0].id,
        companyName: companies[0].name,
        userEmail: `customer${index}@example.test`,
        productName: '测试卡产品',
        maskedPan: '•••• ' + (index ? '8888' : '5678'),
        formFactor: 'virtual_card',
        produceStatus: null,
        trackingNumber: null,
        balance: '20.00000000',
        overflowBalance: '0.00000000',
        providerBalance: '20.00000000',
        balanceLimit: null,
        effectiveBalanceLimit: null,
        currency: 'USD',
        balanceUpdatedAt: null,
        lastTransactionSyncAt: null,
        providerStatus: 'normal',
    }),
);
const paginated = (data) => ({
    data,
    total: data.length,
    current_page: 1,
    last_page: 1,
    prev_page_url: null,
    next_page_url: null,
});
for (const [engineName, engine, launch] of [
    ['chromium', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...launch });
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
        const page = await context.newPage();
        page.setDefaultTimeout(15000);
        const errors = [],
            forbidden = [],
            creates = [],
            advances = [],
            retries = [];
        const tasks = [],
            details = new Map();
        let reloads = 0,
            failNext = false;
        const props = () => ({
            errors: {},
            publicAssets: [],
            unreadSupport: 0,
            unreadMessages: 0,
            requestId: '44444444-4444-4444-8444-444444444444',
            i18n: {
                surface: 'platform',
                locale: 'zh-CN',
                enabledLocales: ['zh-CN', 'en'],
                timezone: 'Asia/Shanghai',
            },
            tenant: null,
            auth: {
                admin: { name: '测试管理员', permissions: ['cards.read', 'card_product.manage'] },
                user: null,
            },
            flash: { success: null },
            companies,
            cards: paginated(
                cards.map((card) => ({
                    ...card,
                    lastTransactionSyncAt: tasks.some((task) => task.status === 'COMPLETED')
                        ? new Date().toISOString()
                        : null,
                })),
            ),
            orders: paginated([]),
            loads: paginated([]),
            filters: { tab: 'cards' },
        });
        page.on('pageerror', (error) => errors.push(error.message));
        await context.route('**/*', async (route) => {
            const request = route.request(),
                url = new URL(request.url()),
                path = url.pathname,
                method = request.method();
            if (url.origin !== origin) {
                forbidden.push(request.url());
                return route.abort();
            }
            if (path.startsWith('/build/')) {
                const body = readFileSync('public' + path);
                return route.fulfill({
                    body,
                    contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript',
                });
            }
            if (path === '/platform/cards' && method === 'GET') {
                const state = {
                    component: 'platform/Cards',
                    props: props(),
                    url: path + url.search,
                    version: 'fixture',
                };
                state.props.filters.tab = url.searchParams.get('tab') ?? 'cards';
                if (request.headers()['x-inertia']) {
                    reloads++;
                    return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state });
                }
                return route.fulfill({
                    contentType: 'text/html',
                    body: `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic-only">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
                });
            }
            const base = '/platform/card-transaction-batches';
            if (path === base + '/preview' && method === 'POST') {
                const body = request.postDataJSON();
                return route.fulfill({
                    json: {
                        total: body.card_ids?.length ?? 2,
                        eligible: body.card_ids?.length ?? 2,
                        skipped: 0,
                    },
                });
            }
            if (path === base && method === 'GET')
                return route.fulfill({ json: { items: [...tasks].reverse() } });
            if (path === base && method === 'POST') {
                const body = request.postDataJSON();
                creates.push(body);
                const task = {
                    id: `55555555-5555-4555-8555-${String(tasks.length + 1).padStart(12, '0')}`,
                    tenant_id: body.tenant_id,
                    date_from: body.date_from,
                    date_to: body.date_to,
                    created_at: new Date().toISOString(),
                    execution_mode: 'browser',
                    scope: body.card_ids ? 'selected' : body.tenant_id ? 'company' : 'all',
                    status: 'RUNNING',
                    counts: {
                        total: body.card_ids?.length ?? 2,
                        pending: body.card_ids?.length ?? 2,
                        succeeded: 0,
                        failed: 0,
                        skipped: 0,
                        pages: 0,
                        records: 0,
                    },
                };
                tasks.push(task);
                details.set(task.id, { items: [], page: 1, hasMore: false });
                return route.fulfill({ status: 202, json: { id: task.id } });
            }
            if (path.startsWith(base + '/')) {
                const [id, action] = path.slice(base.length + 1).split('/'),
                    task = tasks.find((task) => task.id === id);
                assert.ok(task, path);
                if (action === 'advance' && method === 'POST') {
                    advances.push(id);
                    if (failNext) {
                        failNext = false;
                        return route.fulfill({ status: 503, json: {} });
                    }
                    task.counts.pages++;
                    task.counts.records += 20;
                    if (task.counts.pages >= 2) {
                        task.status = 'COMPLETED';
                        task.counts.succeeded = task.counts.total;
                        task.counts.pending = 0;
                    }
                    return route.fulfill({ json: { status: task.status, waitMs: 10000 } });
                }
                if (action === 'retry' && method === 'POST') {
                    retries.push(id);
                    task.status = 'RUNNING';
                    task.counts.pending = task.counts.failed;
                    task.counts.failed = 0;
                    return route.fulfill({ json: { id } });
                }
                if (!action && method === 'GET')
                    return route.fulfill({
                        json: {
                            status: task.status,
                            counts: task.counts,
                            details: details.get(id),
                        },
                    });
            }
            forbidden.push(method + ' ' + path);
            return route.abort();
        });
        await page.goto(origin + '/platform/cards?tab=cards');
        await page.getByText('暂无同步任务。', { exact: true }).waitFor();
        assert.equal(advances.length, 0);
        const first = page.locator('tbody tr').filter({ hasText: '•••• 5678' });
        await first.getByRole('button', { name: '同步', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: '昨天', exact: true }).click();
        await dialog.getByRole('button', { name: '确认并开始同步', exact: true }).click();
        await page.getByRole('button', { name: '暂停', exact: true }).waitFor();
        assert.deepEqual(creates[0].card_ids, [cards[0].id]);
        assert.equal(creates[0].tenant_id, companies[0].id);
        assert.equal(creates[0].date_from, creates[0].date_to);
        await page.getByRole('button', { name: '暂停', exact: true }).click();
        const stopped = advances.length;
        await page.reload();
        await page.getByRole('button', { name: '继续同步', exact: true }).waitFor();
        await page.waitForTimeout(1300);
        assert.equal(advances.length, stopped, 'Reload must not auto-start work');
        await page.getByRole('button', { name: '继续同步', exact: true }).click();
        await page.getByText('同步完成', { exact: true }).waitFor();
        assert.equal(advances.length, 2);
        assert.ok(reloads > 0, 'Completion should refresh recorded card data');
        await page.getByRole('checkbox', { name: '选择本页卡片', exact: true }).click();
        await page.getByRole('button', { name: '同步所选卡片（2 张）', exact: true }).click();
        await dialog.getByRole('button', { name: '近7天', exact: true }).click();
        failNext = true;
        await dialog.getByRole('button', { name: '确认并开始同步', exact: true }).click();
        await page.getByRole('button', { name: '继续同步', exact: true }).waitFor();
        assert.deepEqual(
            creates[1].card_ids,
            cards.map((card) => card.id),
        );
        assert.equal(
            (Date.parse(creates[1].date_to) - Date.parse(creates[1].date_from)) / 86400000,
            6,
        );
        assert.equal(creates[1].tenant_id, null);
        const failed = tasks[1];
        failed.status = 'PARTIAL_FAILED';
        failed.counts.pending = 0;
        failed.counts.failed = failed.counts.total;
        failed.counts.pages = 1;
        await page.getByRole('button', { name: '刷新', exact: true }).click();
        await page.getByRole('button', { name: '重试失败卡片', exact: true }).click();
        await page.getByText('同步完成', { exact: true }).nth(1).waitFor();
        assert.equal(retries.length, 1);
        await page.getByRole('button', { name: '批量同步流水', exact: true }).click();
        await dialog.getByLabel('公司', { exact: true }).selectOption(companies[0].id);
        await dialog.getByRole('button', { name: '确认并开始同步', exact: true }).click();
        await page.getByRole('button', { name: '暂停', exact: true }).waitFor();
        assert.equal(creates[2].tenant_id, companies[0].id);
        assert.equal('card_ids' in creates[2], false);
        await page.getByRole('tab', { name: '开卡订单', exact: true }).click();
        await page.waitForURL('**tab=orders');
        const left = advances.length;
        await page.waitForTimeout(1300);
        assert.equal(advances.length, left);
        await page.reload();
        assert.equal(
            await page
                .getByRole('tab', { name: '开卡订单', exact: true })
                .getAttribute('aria-selected'),
            'true',
        );
        await page.getByRole('tab', { name: '卡片', exact: true }).click();
        await page.getByRole('button', { name: '继续同步', exact: true }).waitFor();
        await page.screenshot({ path: `${out}/${engineName}.png`, fullPage: true });
        for (const width of [1024, 1366, 1920]) {
            await page.setViewportSize({ width, height: 768 });
            const table = page.locator('main table').last();
            const row = table.locator('tbody tr').first();
            const actions = row.locator('td').last();
            await table.locator('..').evaluate((el) => {
                el.scrollLeft = el.scrollWidth;
            });
            assert.ok(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            );
            assert.ok(
                await actions.evaluate((el) => {
                    const rect = el.getBoundingClientRect();
                    const parent = el.closest('table').parentElement.getBoundingClientRect();
                    return (
                        getComputedStyle(el).position === 'sticky' &&
                        Math.abs(rect.right - parent.right) < 2 &&
                        rect.height < 110
                    );
                }),
                `${engineName} fixed actions at ${width}`,
            );
            await row.getByRole('button', { name: '同步', exact: true }).click();
            assert.ok(
                await dialog.evaluate((el) => {
                    const rect = el.getBoundingClientRect();
                    return (
                        rect.left >= 0 &&
                        rect.right <= innerWidth &&
                        rect.top >= 15 &&
                        rect.bottom <= innerHeight - 15
                    );
                }),
            );
            await page.keyboard.press('Escape');
            await dialog.waitFor({ state: 'hidden' });
        }
        assert.deepEqual(errors, []);
        assert.deepEqual(forbidden, []);
        console.log(
            'PASS ' +
                engineName +
                ': single/selected/company scope, presets, automatic paging, pause/reload/resume, failure retry, saved tab, 1024/1366/1920 fixed actions and dialogs, no real mutations',
        );
    } finally {
        await browser.close();
    }
}
