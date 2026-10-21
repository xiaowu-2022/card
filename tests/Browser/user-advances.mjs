import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { mkdirSync, readFileSync } from 'node:fs';

const port = 5247;
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = manifest['resources/js/app.tsx']?.css ?? Object.values(manifest).find(item => item.isEntry && item.css)?.css ?? [];
const styles = css.map(path => `<link rel="stylesheet" href="/build/${path}">`).join('');
const server = await createServer({ configFile: false, root: process.cwd(), resolve: { alias: { '@': resolve('resources/js') } }, plugins: [react(), {
    name: 'user-advance-fixture',
    configureServer(s) { s.middlewares.use('/__advances', async (_req, res) => { res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml('/__advances', '<html><head>' + styles + '</head><body><div id="root"></div><script type="module" src="/advance-fixture.tsx"></script></body></html>')); }); },
    resolveId(id) { if (id.endsWith('/advance-fixture.tsx')) return resolve('advance-fixture.tsx'); },
    load(id) { if (id === resolve('advance-fixture.tsx')) return `import React from 'react';import {createRoot} from 'react-dom/client';import {UserAdvancesPanel} from '/resources/js/components/admin/UserAdvancesPanel';import {clientI18n} from '/resources/js/i18n';await clientI18n.changeLanguage('zh-CN');createRoot(document.getElementById('root')).render(<UserAdvancesPanel user={{id:'user',companyId:'tenant'}}/>);`; },
}], server: { watch: { ignored: ['**/mobile/**', '**/public/**', '**/artifacts/**', '**/storage/**', '**/output/**'] }, host: '127.0.0.1', port, strictPort: true } });
await server.listen();
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.setDefaultTimeout(120000);
    const errors = []; const posts = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/platform/tenants/tenant/users/user/advances**', async route => {
        if (route.request().method() === 'POST') {
            posts.push(route.request().postDataJSON());
            return route.fulfill({ json: { id: 'new-entry' } });
        }
        const number = Number(new URL(route.request().url()).searchParams.get('page'));
        return route.fulfill({ json: { amount: '-2.00000000', page: number, pages: 2, total: 21, items: number === 2 ? [] : [
            { id: 'manual', amount: '-2.00000000', business_date: '2026-10-21', note: '<script>unsafe</script>', reverses_id: null, reversed: false, order_backed: false, created_at: '2026-10-21T00:00:00Z' },
            { id: 'order', amount: '80.00000000', business_date: '2026-10-21', note: 'Deposit receipt', reverses_id: null, reversed: false, order_backed: true, created_at: '2026-10-21T00:00:00Z' },
        ] } });
    });
    await page.goto(`http://127.0.0.1:${port}/__advances`);
    await page.getByRole('heading', { name: '预支金额: -2.00000000 USDT' }).waitFor();
    await page.getByLabel('金额', { exact: true }).fill('-3.12345678');
    await page.getByLabel('备注', { exact: true }).fill('Offline signed offset');
    const submit = page.locator('button[type="submit"]').first();
    assert.equal(await submit.isDisabled(), true);
    await page.getByText('确认登记该用户的负预支对冲。', { exact: true }).click();
    await submit.click();
    await page.waitForFunction(() => document.querySelector('input[inputmode="decimal"]').value === '');
    assert.equal(posts.length, 1); assert.equal(posts[0].amount, '-3.12345678'); assert.equal(posts[0].confirmed, true);
    await page.getByRole('button', { name: '冲销', exact: true }).click();
    await page.getByLabel('备注', { exact: true }).fill('Offline reversal');
    await page.getByText('确认登记该用户的负预支对冲。', { exact: true }).click();
    await submit.click();
    await page.waitForFunction(() => document.querySelector('input[inputmode="decimal"]').value === '');
    assert.equal(posts[1].reverses_id, 'manual'); assert.equal(posts[1].amount, '-2.00000000');
    await page.getByText('充值关联预支请通过订单到账类型切换进行冲正。', { exact: true }).waitFor();
    assert.equal(await page.locator('script').filter({ hasText: 'unsafe' }).count(), 0);
    mkdirSync('artifacts/user-advances', { recursive: true });
    await page.screenshot({ path: 'artifacts/user-advances/admin-390.png', fullPage: true });
    await page.getByRole('button', { name: '2', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('article'));
    assert.deepEqual(errors, []);
    console.log('User advance browser fixture: signed entry, confirmation, reversal, safe notes, order barrier and numbered pagination passed.');
} finally { await browser.close(); await server.close(); }
