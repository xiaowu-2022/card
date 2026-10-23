// Offline UI acceptance: every API response is synthetic and all nonlocal traffic is blocked.
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { chromium, webkit } from 'playwright';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { addParityStates } from '../../scripts/client/parity-states.mjs';

const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')),
);
const report = structuredClone(fixture.pages['/promotion/stock'].props.report);
Object.assign(report, {
    version: 'partner',
    stock: '-39.87654322',
    childrenStock: '-43.00000000',
    annualSubsidy: '1.00000000',
    subordinateAdvances: '4.00000000',
    share: '-15.95061729',
    negative: true,
    cashFlow: null,
    flowDetails: null,
});
const detail = (page) => ({
    direction: 'childrenStock',
    page,
    total: 21,
    hasMore: page === 1,
    items: [
        {
            id: 'child-' + page,
            source: 'childrenStock',
            account_id: 'CHILD-' + page,
            email: null,
            direct_account_id: null,
            direct_email: null,
            asset_code: 'USDT',
            amount: '-43.00000000',
            amountUsdt: '-43.00000000',
            stock: '-46.00000000',
            annualSubsidy: '3.00000000',
            posted_at: null,
        },
    ],
});
const root = resolve('mobile/uni-app/dist/build/h5'),
    origin = 'http://127.0.0.1:5257';
const output = 'output/partner-recursive-stock';
mkdirSync(output, { recursive: true });
const server = await createServer({
    configFile: false,
    root: process.cwd(),
    resolve: { alias: { '@': resolve('resources/js') } },
    plugins: [
        react(),
        {
            name: 'recursive-stock-fixture',
            configureServer(s) {
                s.middlewares.use('/__recursive', async (_req, res) => {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await s.transformIndexHtml(
                            '/__recursive',
                            '<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/recursive-stock-fixture.tsx"></script></body></html>',
                        ),
                    );
                });
            },
            resolveId(id) {
                if (id.endsWith('/recursive-stock-fixture.tsx'))
                    return resolve('recursive-stock-fixture.tsx');
            },
            load(id) {
                if (id === resolve('recursive-stock-fixture.tsx'))
                    return `import React,{useState} from 'react'; import {createRoot} from 'react-dom/client'; import {PartnerStockReport} from '/resources/js/components/user/PartnerStockReport'; import {configureClientLocale} from '/resources/js/i18n'; import {PlatformUiContext} from '/resources/js/components/admin/platform-ui-context'; import '/resources/css/partner-stock.css'; configureClientLocale(new URLSearchParams(location.search).get('lang') || 'en','Asia/Shanghai'); const report=${JSON.stringify(report)}; const detail=${detail.toString()}; function App(){const [flow,setFlow]=useState(null);return <PlatformUiContext.Provider value={true}><PartnerStockReport report={{...report,flowDetails:flow}} onPage={()=>{}} onFlow={(direction,page=1)=>setFlow(direction?detail(page):null)}/></PlatformUiContext.Provider>} createRoot(document.getElementById('root')).render(<App/>);`;
            },
        },
    ],
    server: { host: '127.0.0.1', port: 5258, strictPort: true },
});
await server.listen();
try {
    for (const [engineName, engine, options] of [
        ['chromium', chromium, { channel: 'chrome' }],
        ['webkit', webkit, {}],
    ]) {
        const browser = await engine.launch({ headless: true, ...options });
        try {
            for (const [lang, label, shown, restored] of [
                ['en', 'Subordinate partner stock', 'Displayed stock', 'Subsidy added back'],
                ['zh-CN', '下级合伙人传递存量', '展示存量', '补贴还原额'],
                ['ms', 'Stok rakan kongsi bawahan', 'Stok dipaparkan', 'Subsidi ditambah semula'],
                [
                    'es',
                    'Existencias transferidas de socios subordinados',
                    'Existencias mostradas',
                    'Subsidio restituido',
                ],
            ]) {
                const context = await browser.newContext({ viewport: { width: 375, height: 900 } });
                await context.addInitScript(() =>
                    localStorage.setItem('consumer-bearer-v1', '12|' + 'A'.repeat(64)),
                );
                const page = await context.newPage(),
                    errors = [],
                    flows = [];
                page.on('pageerror', (e) => errors.push(e.message));
                await context.route('**/*', (route) => {
                    const req = route.request(),
                        url = new URL(req.url());
                    if (url.origin === 'http://127.0.0.1:5258') return route.continue();
                    if (url.origin !== origin) return route.abort();
                    if (url.pathname.startsWith('/api/mobile/v1')) {
                        const path = url.pathname.slice('/api/mobile/v1'.length);
                        if (path === '/presence') return route.fulfill({ json: {} }); // Simulated lifecycle heartbeat, no real write.
                        assert.equal(req.method(), 'GET', 'unexpected write: ' + url.pathname);
                        if (path === '/bootstrap')
                            return route.fulfill({
                                json: {
                                    ...fixture.authenticated,
                                    locale: lang,
                                    unread: null,
                                    publicAssets: {},
                                },
                            });
                        if (path === '/client/promotion/stock') {
                            const value = structuredClone(report);
                            if (url.searchParams.has('flow')) {
                                assert.equal(url.searchParams.get('flow'), 'childrenStock');
                                const n = Number(url.searchParams.get('flow_page') || 1);
                                flows.push(n);
                                value.flowDetails = detail(n);
                            }
                            return route.fulfill({
                                json: { component: 'user/PartnerStock', props: { report: value } },
                            });
                        }
                        return route.fulfill({ json: fixture.api[path] ?? {} });
                    }
                    const file = resolve(
                        root,
                        '.' + (url.pathname === '/' ? '/index.html' : url.pathname),
                    );
                    if (!file.startsWith(root + '/') || !existsSync(file)) return route.abort();
                    return route.fulfill({
                        body: readFileSync(file),
                        contentType:
                            {
                                '.js': 'text/javascript',
                                '.css': 'text/css',
                                '.html': 'text/html',
                                '.json': 'application/json',
                                '.svg': 'image/svg+xml',
                                '.png': 'image/png',
                            }[extname(file)] ?? 'application/octet-stream',
                    });
                });
                // Administrative report supports both backend locales.
                if (['en', 'zh-CN'].includes(lang)) {
                    await page.goto('http://127.0.0.1:5258/__recursive?lang=' + lang);
                    await page
                        .getByRole('button', { name: new RegExp(label) })
                        .first()
                        .click();
                    await page.getByText(new RegExp(shown)).waitFor();
                    assert.ok(
                        (await page.locator('.stock-flow-row').innerText()).includes(restored),
                    );
                    for (const width of [320, 430, 1440]) {
                        await page.setViewportSize({ width, height: 900 });
                        assert.equal(
                            await page.evaluate(
                                () => document.documentElement.scrollWidth <= innerWidth,
                            ),
                            true,
                        );
                    }
                    await page.screenshot({
                        path: `${output}/${engineName}-admin-${lang}.png`,
                        fullPage: true,
                    });
                }
                await page.goto(origin + '/#/pages/screen/index?path=%2Fpromotion%2Fstock');
                await page.locator('.flow-link').filter({ hasText: label }).first().click();
                await page.getByText(new RegExp(shown)).waitFor();
                assert.ok((await page.locator('.flow-item').innerText()).includes(restored));
                assert.match(await page.locator('.flow-item').innerText(), /-46\.00000000/);
                assert.match(await page.locator('.flow-item').innerText(), /-43\.00000000/);
                const pagination = page.locator('.report-pagination .secondary');
                await pagination.last().click();
                await page.getByText('CHILD-2', { exact: false }).waitFor();
                assert.deepEqual(flows, [1, 2]);
                for (const width of [320, 375, 430, 768, 1440]) {
                    await page.setViewportSize({ width, height: 900 });
                    assert.equal(
                        await page.evaluate(
                            () => document.documentElement.scrollWidth <= innerWidth,
                        ),
                        true,
                    );
                }
                await page.screenshot({
                    path: `${output}/${engineName}-h5-${lang}.png`,
                    fullPage: true,
                });
                assert.deepEqual(errors, []);
                await context.close();
                console.log(
                    `PASS ${engineName} ${lang}: recursive stock, subsidy restoration, pagination and responsive layout`,
                );
            }
        } finally {
            await browser.close();
        }
    }
} finally {
    await server.close();
}
