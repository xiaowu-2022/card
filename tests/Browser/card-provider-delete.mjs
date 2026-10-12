import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

const origin = 'http://admin.localhost:8000';
const path = '/platform/card-providers';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
const output = 'artifacts/card-provider-delete';
mkdirSync(output, { recursive: true });
const unused = { id: '11111111-1111-4111-8111-111111111111', name: 'Unused fixture', referenceBalance: '123456789012.34', asset: 'USDT', version: 3, localMock: false, apiConnected: false, sandbox: false, issuedCardCount: '0', photonpay: null, updatedAt: '2026-10-12T04:00:00Z', canDelete: true };
const used = { ...unused, id: '22222222-2222-4222-8222-222222222222', name: 'Linked fixture', canDelete: false };

for (const [name, engine] of [['chrome', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch(name === 'chrome' ? { channel: 'chrome' } : {});
    try {
        const page = await browser.newPage();
        const errors = [];
        const unexpected = [];
        const mutations = [];
        let records = [unused, used];
        let permissions = ['tenant.read', 'provider_operation.read', 'card_provider_reference.manage'];
        let locale = 'zh-CN';
        let fail = false;
        page.on('pageerror', (error) => errors.push(error.message));
        const state = (formErrors = {}) => ({ component: 'platform/CardProviders', url: path, version: 'delete-fixture', props: {
            errors: formErrors, publicAssets: [], tenant: null, flash: {},
            auth: { admin: { name: 'Offline administrator', permissions }, user: null },
            i18n: { locale, enabledLocales: ['zh-CN', 'en'], timezone: 'Asia/Kuala_Lumpur', surface: 'platform' },
            providers: { data: records, total: records.length, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        } });
        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname.startsWith('/build/') && request.method() === 'GET') return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
            if (request.method() === 'DELETE' && url.pathname === `${path}/${unused.id}`) {
                const body = request.postDataJSON();
                mutations.push(body);
                assert.equal(body.version, 3);
                assert.equal(body.confirmed, true);
                assert.match(body.request_id, /^[0-9a-f-]{36}$/);
                if (!fail) records = records.filter((record) => record.id !== unused.id);
                return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state(fail ? { form: 'This card provider was updated elsewhere. Refresh before deleting.' } : {}) });
            }
            if (request.method() !== 'GET' || url.pathname !== path) { unexpected.push(`${request.method()} ${url.pathname}`); return route.abort(); }
            if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state() });
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state()).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
        });
        for (const language of ['zh-CN', 'en']) {
            locale = language;
            for (const width of [375, 768, 1440]) {
                records = [unused, used];
                await page.setViewportSize({ width, height: 900 });
                await page.goto(origin + path);
                const row = page.getByRole('row').filter({ hasText: unused.name });
                await row.waitFor();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                const label = locale === 'zh-CN' ? '删除' : 'Delete';
                assert.equal(await page.getByRole('row').filter({ hasText: used.name }).getByRole('button', { name: label, exact: true }).isEnabled(), false);
                await row.getByRole('button', { name: label, exact: true }).click();
                const dialog = page.getByRole('dialog');
                await dialog.getByText(locale === 'zh-CN' ? '确认删除卡商“Unused fixture”？删除后无法恢复。' : 'Delete Unused fixture? This cannot be undone.', { exact: true }).waitFor();
                if (locale === 'zh-CN') await page.screenshot({ path: `${output}/${name}-${width}.png` });
                const count = mutations.length;
                await dialog.getByRole('button', { name: locale === 'zh-CN' ? '取消' : 'Cancel', exact: true }).click();
                assert.equal(mutations.length, count);
                await row.getByRole('button', { name: label, exact: true }).click();
                await dialog.getByRole('button', { name: locale === 'zh-CN' ? '确认删除' : 'Confirm deletion', exact: true }).click();
                await dialog.waitFor({ state: 'hidden' });
                assert.equal(await page.getByRole('row').filter({ hasText: unused.name }).count(), 0);
                assert.equal(mutations.length, count + 1);
            }
        }
        locale = 'zh-CN'; records = [unused, used]; fail = true;
        await page.goto(origin + path);
        await page.getByRole('row').filter({ hasText: unused.name }).getByRole('button', { name: '删除', exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: '确认删除', exact: true }).click();
        await page.getByText('该卡商已被更新，请刷新后再删除。', { exact: true }).waitFor();
        assert.equal(await page.locator('tbody tr').filter({ hasText: unused.name }).count(), 1);
        permissions = ['provider_operation.read'];
        await page.goto(origin + path);
        await page.getByRole('row').filter({ hasText: unused.name }).waitFor();
        assert.equal(await page.getByRole('button', { name: '删除', exact: true }).count(), 0);
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        console.log(`PASS ${name}: two languages, three widths, confirmation/cancel, referenced blockers, exact deletion payload, stale errors and permissions; synthetic responses only.`);
    } finally { await browser.close(); }
}
