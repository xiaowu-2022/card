import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')));
const mobileOrigin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5239';
const adminPort = Number(process.env.PARTNER_ADMIN_PORT ?? 5238);
const root = '11111111-1111-4111-8111-111111111111';
const child = '22222222-2222-4222-8222-222222222222';
const grandchild = '33333333-3333-4333-8333-333333333333';
const identity = id => ({ id, name: id === root ? 'Root partner' : id === child ? 'Child partner' : 'Grandchild partner', accountId: id.slice(0, 8) });
const listing = (id, number) => ({ subject: identity(id), listPath: '/promotion/stock/partners/' + id, page: number, total: id === grandchild ? 0 : 21, hasMore: number === 1 && id !== grandchild,
    items: id === grandchild ? [] : Array.from({ length: number === 1 ? 20 : 1 }, (_, i) => ({ ...identity(id === root ? child : grandchild), id: i === 0 ? (id === root ? child : grandchild) : `44444444-4444-4444-8444-${String(i).padStart(12, '0')}`, name: `${id === root ? 'Child partner' : 'Grandchild partner'} ${i + 1}`, email: 'partner.with.long.email@example.test', stock: i === 0 ? '-12345.12345678' : '0.00000000', teamCount: 12 })) });
const report = id => ({ ...structuredClone(fixture.pages['/promotion/stock'].props.report), version: 'partner', subject: identity(id), partnerId: id, reportPath: `/promotion/stock/partners/${id}/report`, partnersPath: `/promotion/stock/partners/${id}`, accountBalance: null, cashFlow: null, trends: {}, flowDetails: null });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const server = await createServer({ configFile: false, root: process.cwd(), resolve: { alias: { '@': resolve('resources/js') } }, plugins: [react(), {
    name: 'hierarchy-fixture',
    configureServer(s) { s.middlewares.use('/__hierarchy', async (_req, res) => { res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml('/__hierarchy', '<html><body><div id="root"></div><script type="module" src="/hierarchy-fixture.tsx"></script></body></html>')); }); },
    resolveId(id) { if (id.endsWith('/hierarchy-fixture.tsx')) return resolve('hierarchy-fixture.tsx'); },
    load(id) { if (id === resolve('hierarchy-fixture.tsx')) return `import React from 'react'; import {createRoot} from 'react-dom/client'; import {PartnerHierarchyPanel} from '/resources/js/components/admin/PartnerHierarchyPanel'; createRoot(document.getElementById('root')).render(<div data-detail-body style={{height:400,overflow:'auto'}}><PartnerHierarchyPanel partner="${root}" company={null} onClose={()=>{document.body.dataset.closed='yes'}} /></div>);`; },
}], server: { host: '127.0.0.1', port: adminPort, strictPort: true } });
await server.listen();
try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 750 } });
    await page.route('**/platform/partners/**', route => {
        const u = new URL(route.request().url()), parts = u.pathname.split('/');
        assert.equal(route.request().method(), 'GET');
        return route.fulfill({ json: parts.at(-1) === 'stock' ? { report: report(parts.at(-2)) } : listing(parts.at(-2), Number(u.searchParams.get('page') || 1)) });
    });
    await page.goto(`http://127.0.0.1:${adminPort}/__hierarchy`);
    await page.getByRole('heading', { name: 'Partner data · Root partner' }).waitFor();
    await page.getByRole('button', { name: 'Details', exact: true }).nth(14).scrollIntoViewIfNeeded();
    const adminScroll = await page.locator('[data-detail-body]').evaluate(el => el.scrollTop);
    assert.ok(adminScroll > 100);
    await page.getByRole('button', { name: 'Details', exact: true }).nth(14).click();
    await page.getByRole('heading', { name: 'Stock data · Grandchild partner' }).waitFor();
    await page.getByRole('button', { name: 'Back', exact: true }).first().click();
    await page.waitForFunction(expected => Math.abs(document.querySelector('[data-detail-body]').scrollTop - expected) < 5, adminScroll);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1);
    await page.getByRole('button', { name: 'Details', exact: true }).click();
    await page.getByRole('heading', { name: 'Stock data · Child partner' }).waitFor();
    await page.getByRole('button', { name: 'Back', exact: true }).first().click();
    await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1);
    await page.getByRole('button', { name: 'Subordinate partners', exact: true }).click();
    await page.getByRole('heading', { name: 'Partner data · Child partner' }).waitFor();
    await page.getByRole('button', { name: 'Subordinate partners', exact: true }).first().click();
    await page.getByText('No subordinate partners', { exact: true }).waitFor();
    await page.close();
    console.log('Admin hierarchy: pagination, details, nested children and back passed');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobile = await context.newPage();
    const errors = []; mobile.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', route => {
        const u = new URL(route.request().url());
        if (u.pathname.startsWith('/api/v1')) {
            const key = u.pathname.slice(7);
            if (key === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
            if (key.startsWith('/client/promotion/stock')) {
                assert.equal(route.request().method(), 'GET');
                const parts = key.split('/'), isReport = key.endsWith('/report');
                const id = parts.at(-1) === 'partners' ? root : isReport ? parts.at(-2) : parts.at(-1);
                const dto = structuredClone(fixture.pages['/promotion/stock']); dto.props.i18n.locale = 'en';
                dto.component = isReport ? 'user/PartnerStock' : 'user/PartnerChildren';
                dto.props = { ...dto.props, ...(isReport ? { report: report(id) } : { partners: listing(id, Number(u.searchParams.get('page') || 1)) }) };
                return route.fulfill({ json: dto });
            }
            return route.fulfill({ json: fixture.api[key] ?? { messages: 0, support: 0 } });
        }
        if (u.origin !== mobileOrigin) return route.abort();
        return route.continue();
    });
    await mobile.goto(mobileOrigin + '/#/pages/screen/index?path=' + encodeURIComponent('/promotion/stock/partners'));
    await mobile.locator('.partner-list .heading').getByText('Root partner').waitFor();
    for (const width of [320, 390, 430]) {
        await mobile.setViewportSize({ width, height: 844 });
        const row = mobile.locator('.partner-row').first();
        await row.locator('.email').getByText('partner.with.long.email@example.test', { exact: true }).waitFor();
        await row.locator('.stock-amount').getByText('-12345.12345678 USDT', { exact: true }).waitFor();
        assert.equal(await row.evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
        const identityBox = await row.locator('.partner-identity').boundingBox();
        const amountBox = await row.locator('.partner-stock').boundingBox();
        assert.ok(identityBox.x + identityBox.width <= amountBox.x + 1 || identityBox.y + identityBox.height <= amountBox.y + 1);
        mkdirSync('artifacts/partner-hierarchy', { recursive: true });
        await mobile.screenshot({ path: `artifacts/partner-hierarchy/stock-email-${width}.png` });
    }
    await mobile.setViewportSize({ width: 390, height: 844 });
    await mobile.locator('.partner-row').nth(14).scrollIntoViewIfNeeded();
    const mobileScroll = await mobile.evaluate(() => window.scrollY);
    assert.ok(mobileScroll > 100);
    await mobile.locator('.partner-row').nth(14).getByText('Details', {exact:true}).click();
    await mobile.locator('.stock > .heading').getByText('Grandchild partner', {exact:true}).waitFor();
    await mobile.locator('button[aria-label="Back"], uni-button[aria-label="Back"]').last().click();
    await mobile.waitForFunction(expected => Math.abs(window.scrollY - expected) < 10, mobileScroll);
    await mobile.getByText('Next', { exact: true }).last().click();
    await mobile.waitForFunction(() => document.querySelectorAll('.partner-row').length === 1);
    await mobile.getByText('Details', { exact: true }).last().click();
    await mobile.locator('.stock > .heading').getByText('Child partner', { exact: true }).waitFor();
    await mobile.locator('button[aria-label="Back"], uni-button[aria-label="Back"]').last().click();
    await mobile.waitForFunction(() => document.querySelectorAll('.partner-row').length === 1);
    await mobile.getByText('Subordinate partners', { exact: true }).last().click();
    await mobile.locator('.partner-list .heading').getByText('Child partner').waitFor();
    assert.deepEqual(errors, []);
    console.log('H5 hierarchy: pagination, details, back and nested children passed');
    await context.close();
} finally { await server.close(); await browser.close(); }
