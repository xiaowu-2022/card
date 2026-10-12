import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const origin = 'http://admin.localhost:8000';
const path = '/platform/cards?tab=cards';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const output = 'artifacts/card-void'; mkdirSync(output, { recursive: true });
const company = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const cards = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'].map((id, index) => ({ id, tenantId: company, balance: '25.12345678', overflowBalance: '5.12345678', providerBalance: '20', balanceLimit: null, effectiveBalanceLimit: null, currency: 'USD', balanceUpdatedAt: null, lastTransactionSyncAt: null, companyName: 'Offline company', userEmail: `fixture${index}@example.test`, productName: 'Offline card', maskedPan: `•••• ${index ? '8888' : '1234'}`, formFactor: 'virtual_card', produceStatus: null, trackingNumber: null, providerStatus: 'normal' }));
const batchId = '33333333-3333-4333-8333-333333333333';
for (const [name, engine] of [['chrome', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch(name === 'chrome' ? { channel: 'chrome' } : {});
    try {
        const page = await browser.newPage();
        const unexpected = [], errors = [], writes = [];
        let locale = 'zh-CN', canVoid = true, selectedIds = [], advances = 0, unknown = false;
        page.on('pageerror', (error) => errors.push(error.message));
        const paginated = (data) => ({ data, total: data.length, current_page: 1, last_page: 1, next_page_url: null, prev_page_url: null });
        const state = () => ({ component: 'platform/Cards', url: path, version: 'void-fixture', props: { errors: {}, publicAssets: [], tenant: null, flash: {}, auth: { admin: { name: 'Offline administrator', permissions: ['cards.read', 'card_product.manage', 'wallet.read', 'ledger.read'] }, user: null }, i18n: { locale, enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Kuala_Lumpur', surface: 'platform' }, orders: paginated([]), loads: paginated([]), cards: paginated(cards), companies: [{ id: company, name: 'Offline company' }], filters: { tab: 'cards' }, canVoidCards: canVoid } });
        const batch = () => {
            const status = advances ? unknown ? 'UNKNOWN' : 'SUCCEEDED' : 'PENDING';
            return { id: batchId, counts: { PENDING: status === 'PENDING' ? selectedIds.length : 0, RUNNING: 0, UNKNOWN: status === 'UNKNOWN' ? selectedIds.length : 0, SUCCEEDED: status === 'SUCCEEDED' ? selectedIds.length : 0, FAILED: 0 }, items: selectedIds.map((id) => { const card = cards.find((c) => c.id === id); return { id, masked_pan: card.maskedPan, company: 'Offline company', account_id: '100001', status, stage: advances ? unknown ? 'RETURN' : 'DONE' : 'CHECK', returned: advances && !unknown ? '24.12345678' : '0', fee: advances && !unknown ? '1' : '0', error_code: unknown ? 'CARD_VOID_UNCONFIRMED' : null }; }) };
        };
        await page.route('**/*', async (route) => {
            const request = route.request(), url = new URL(request.url());
            if (url.pathname.startsWith('/build/') && request.method() === 'GET') return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
            if (url.pathname.startsWith('/platform/card-void-batches')) {
                if (request.method() === 'POST') {
                    const body = request.postDataJSON(); writes.push({ path: url.pathname, body });
                    if (url.pathname === '/platform/card-void-batches') { assert.equal(body.confirmed, true); assert.match(body.request_id, /^[0-9a-f-]{36}$/); selectedIds = body.card_ids; advances = 0; return route.fulfill({ status: 202, json: { id: batchId } }); }
                    if (url.pathname.endsWith('/advance')) advances++;
                    else { unexpected.push(request.method() + ' ' + url.pathname); return route.abort(); }
                    return route.fulfill({ json: batch() });
                }
                return route.fulfill({ json: url.pathname === '/platform/card-void-batches' ? { items: [{ id: batchId, created_at: '2026-10-12T04:00:00Z' }] } : batch() });
            }
            if (request.method() !== 'GET' || url.pathname !== '/platform/cards') { unexpected.push(request.method() + ' ' + url.pathname); return route.abort(); }
            if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state() });
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic-offline-token"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state()).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
        });
        for (const language of ['zh-CN', 'en']) {
            locale = language;
            for (const width of [375, 768, 1440]) {
                await page.setViewportSize({ width, height: 900 }); await page.goto(origin + path);
                const row = page.getByRole('row').filter({ hasText: cards[0].maskedPan }); await row.waitFor();
                await row.getByRole('button', { name: locale === 'zh-CN' ? '更多操作' : 'More actions', exact: true }).click();
                await page.getByRole('menuitem', { name: locale === 'zh-CN' ? '作废卡片' : 'Void card', exact: true }).click();
                const dialog = page.getByRole('dialog'); await dialog.waitFor();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                const count = writes.length;
                await dialog.getByRole('button', { name: locale === 'zh-CN' ? '取消' : 'Cancel', exact: true }).click(); assert.equal(writes.length, count);
                await row.getByRole('checkbox').click();
                await page.getByRole('row').filter({ hasText: cards[1].maskedPan }).getByRole('checkbox').click();
                await page.getByRole('button', { name: locale === 'zh-CN' ? '批量作废（2）' : 'Void selected cards (2)', exact: true }).click();
                if (locale === 'zh-CN') await page.screenshot({ path: `${output}/${name}-${width}.png` });
                await dialog.getByRole('button', { name: locale === 'zh-CN' ? '确认退款并作废卡片' : 'Confirm refund and void cards', exact: true }).click();
                await dialog.getByText(locale === 'zh-CN' ? '已作废: 2' : 'Card voided: 2', { exact: true }).waitFor();
                assert.deepEqual(selectedIds, cards.map((card) => card.id));
                assert.equal(advances, 1);
                await dialog.getByRole('button', { name: locale === 'zh-CN' ? '关闭' : 'Close', exact: true }).click();
            }
        }
        locale = 'zh-CN'; unknown = true;
        await page.goto(origin + path); await page.getByRole('row').filter({ hasText: cards[0].maskedPan }).getByRole('checkbox').click();
        await page.getByRole('button', { name: '批量作废（1）', exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: '确认退款并作废卡片', exact: true }).click();
        await page.getByRole('dialog').getByText('待确认: 1', { exact: true }).waitFor();
        assert.equal(advances, 1); await page.waitForTimeout(1800); assert.equal(advances, 1);
        await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click();
        const count = writes.length;
        await page.getByRole('button', { name: '作废任务', exact: true }).click();
        await page.getByRole('dialog').getByRole('button').filter({ hasText: '2026' }).click();
        await page.getByRole('dialog').getByRole('button', { name: '继续作废', exact: true }).waitFor();
        assert.equal(writes.length, count);
        unknown = false;
        await page.getByRole('dialog').getByRole('button', { name: '继续作废', exact: true }).click();
        await page.getByRole('dialog').getByText('已作废: 1', { exact: true }).waitFor();
        await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click();
        canVoid = false; await page.goto(origin + path);
        await page.getByRole('row').filter({ hasText: cards[0].maskedPan }).waitFor();
        assert.equal(await page.getByRole('button', { name: '作废任务', exact: true }).count(), 0);
        await page.getByRole('row').filter({ hasText: cards[0].maskedPan }).getByRole('button', { name: '更多操作', exact: true }).click();
        assert.equal(await page.getByRole('menuitem', { name: '作废卡片', exact: true }).count(), 0);
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log(`PASS ${name}: single/bulk confirmation, cancel without writes, exact selected IDs, progress, unknown pause/resume, read-only history, two languages/three widths and permissions; synthetic responses only.`);
    } finally { await browser.close(); }
}
