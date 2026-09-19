// Isolated visual acceptance: fixture HTML and local assets only; no financial requests.
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const app = manifest['resources/js/app.tsx'];
let locale = 'zh-CN';
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    if (/^\/(build\/assets|images\/marketing\/growth)\/[a-zA-Z0-9._-]+$/.test(path)) {
        res.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : path.endsWith('.jpg') ? 'image/jpeg' : 'text/javascript');
        res.end(await readFile('public' + path)); return;
    }
    const page = { component: 'user/AssetFlow', url: '/dashboard', version: 'fixture', props: {
        errors: {}, flash: {}, account: null, wallet: null, kycStatus: 'APPROVED',
        auth: { user: { id: 'fixture', status: 'ACTIVE' } },
        tenant: { name: 'Spec Pay', branding: { brandName: 'Spec Pay', primaryColor: '#39ad8d' } },
        i18n: { locale, timezone: 'Asia/Kuala_Lumpur', enabledLocales: ['zh-CN','en','ms','es'], surface: 'user' },
        mode: 'deposit', selectedAsset: 'USDT', result: null, overview: { activation: { qualified: true }, cumulativeCommission: '0', estimate: '0', updatedAt: null,
            assets: ['USDT','USDC','ETH','BTC'].map(asset => ({ asset, available: '0', held: '0', deposit: '0', exchange: false, rails: asset === 'USDT' ? [{code: 'USDT_TRON', network: 'TRON', deposit: true, withdrawal: true}] : [], activity: [] })) },
    }};
    res.setHeader('Content-Type', 'text/html');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${[...new Set([manifest['resources/css/app.css'].file, ...(app.css ?? [])])].map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script type="application/json" data-page="app">${JSON.stringify(page)}</script><div id="app"></div><script type="module" src="/build/${app.file}"></script></body></html>`);
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
    await page.goto(`http://127.0.0.1:${server.address().port}/dashboard`);
    await page.getByRole('button', {name: '选择网络'}).click();
    await page.getByRole('button', {name: 'TRON (TRC20)'}).click();
    await page.locator('input[inputmode="decimal"]').fill('100');
    let submitted;
    await page.route('**/wallet/top-ups', async route => {
        submitted = route.request().postDataJSON();
        await route.abort();
    });
    await page.getByRole('button', {name: '创建充值订单'}).click();
    await page.waitForTimeout(300);
    assert.equal(submitted.requested_amount, '100');
    assert.match(submitted.request_id, /^[a-f0-9-]{36}$/);
    assert.ok(!page.url().includes('/wallet/top-up'));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    console.log('PASS: TRON amount submits directly to existing order endpoint; no second form.');
} finally { await browser.close(); server.close(); }
