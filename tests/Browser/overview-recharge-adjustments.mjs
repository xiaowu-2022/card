import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { readFileSync, mkdirSync } from 'node:fs';

const port = 5248;
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = manifest['resources/js/app.tsx']?.css ?? Object.values(manifest).find(item => item.isEntry && item.css)?.css ?? [];
const styles = css.map(path => `<link rel="stylesheet" href="/build/${path}">`).join('');
const server = await createServer({ configFile: false, root: process.cwd(), resolve: { alias: { '@': resolve('resources/js') } }, plugins: [react(), {
    name: 'overview-recharge-fixture',
    configureServer(s) { s.middlewares.use('/__overview-adjustments', async (_req, res) => { res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml('/__overview-adjustments', '<html><head>' + styles + '</head><body><div id="root"></div><script type="module" src="/overview-adjustment-fixture.tsx"></script></body></html>')); }); },
    resolveId(id) { if (id.endsWith('/overview-adjustment-fixture.tsx')) return resolve('overview-adjustment-fixture.tsx'); },
    load(id) { if (id === resolve('overview-adjustment-fixture.tsx')) return `import React,{useState} from 'react';import {createRoot} from 'react-dom/client';import {OverviewRechargeAdjustmentsDialog} from '/resources/js/components/admin/OverviewRechargeAdjustmentsDialog';import {OverviewSourceDialog} from '/resources/js/components/admin/OverviewSourceDialog';import {clientI18n} from '/resources/js/i18n';await clientI18n.changeLanguage('zh-CN');function Fixture(){const [mode,setMode]=useState('adjust');return <><button onClick={()=>setMode('adjust')}>show-adjustment</button><button onClick={()=>setMode('source')}>show-source</button>{mode==='adjust'?<OverviewRechargeAdjustmentsDialog canManage onClose={()=>setMode('closed')} onSaved={()=>{window.saved=true}}/>:mode==='source'?<OverviewSourceDialog target={{metric:'inflow',period:'total',label:'实际充值金额'}} onClose={()=>setMode('closed')}/>:null}</>};createRoot(document.getElementById('root')).render(<Fixture/>);`; },
}], server: { watch: { ignored: ['**/mobile/**', '**/public/**', '**/artifacts/**', '**/storage/**', '**/output/**'] }, host: '127.0.0.1', port, strictPort: true } });
await server.listen();
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.setDefaultTimeout(120000);
    const errors = []; const posts = []; const sources = [];
    page.on('pageerror', e => errors.push(e.message));
    const history = number => ({ amount: '-2.00000000', recordedAmount: '100.00000000', adjustedAmount: '98.00000000', page: number, pages: 2, total: 21,
        items: number === 2 ? [] : [{ id: 'adjustment', amount: '-2.00000000', business_date: '2026-10-21', reason: '<script>unsafe</script>', actor_name: 'Fixture administrator', created_at: '2026-10-21T00:00:00Z' }] });
    await page.route('**/platform/overview/recharge-adjustments**', async route => {
        if (route.request().method() === 'POST') { posts.push(route.request().postDataJSON()); return route.fulfill({ json: { id: 'new-entry' } }); }
        return route.fulfill({ json: history(Number(new URL(route.request().url()).searchParams.get('page') || 1)) });
    });
    await page.route('**/platform/overview/sources**', async route => {
        const query = new URL(route.request().url()).searchParams; sources.push(Object.fromEntries(query));
        return route.fulfill({ json: { amount: '98.00000000', recordedAmount: '100.00000000', asset: 'USDT', updatedAt: '2026-10-21T04:00:00Z', timezone: 'Asia/Shanghai', rows: { items: [], page: 1, lastPage: 1, total: 0 }, adjustments: history(Number(query.get('adjustment_page') || 1)) } });
    });
    await page.goto(`http://127.0.0.1:${port}/__overview-adjustments`);
    await page.getByText('原始实际充值合计', { exact: true }).waitFor();
    await page.getByLabel('调整金额（U，可正可负）', { exact: true }).fill('-3.12345678');
    await page.getByLabel('调整原因', { exact: true }).fill('Offline display-only correction');
    const submit = page.getByRole('button', { name: '登记调整', exact: true });
    assert.equal(await submit.isDisabled(), true);
    await page.getByText('确认此次负数显示调整。', { exact: true }).click();
    await submit.click();
    await page.waitForFunction(() => window.saved === true);
    assert.equal(posts.length, 1); assert.equal(posts[0].amount, '-3.12345678'); assert.equal(posts[0].confirmed, true); assert.equal(posts[0].reason, 'Offline display-only correction');
    await page.getByText('显示调整记录 · 21', { exact: true }).waitFor();
    assert.equal(await page.locator('script').filter({ hasText: 'unsafe' }).count(), 0);
    mkdirSync('artifacts/overview-recharge-adjustments', { recursive: true });
    await page.screenshot({ path: 'artifacts/overview-recharge-adjustments/admin-390.png', fullPage: true });
    await page.getByRole('button', { name: '2', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('article'));
    await page.getByRole('button', { name: '关闭', exact: true }).click();
    await page.getByRole('button', { name: 'show-source', exact: true }).click();
    await page.getByText('Fixture administrator', { exact: false }).waitFor();
    await page.getByRole('button', { name: '2', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('article'));
    assert.equal(sources.at(-1).adjustment_page, '2'); assert.equal(sources.at(-1).page, '1');
    assert.deepEqual(errors, []);
    console.log('Overview display adjustment fixture passed: signed entry, confirmation, history, safe notes and independent source pagination.');
} finally { await browser.close(); await server.close(); }
