import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
const base = 'http://admin.localhost:8000';
const out = process.env.ASSET_PREVIEW_DIR ?? '/tmp/card-multi-assets';
await mkdir(out, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const context = await browser.newContext({ permissions: ['local-network-access'] });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(30000);
    const errors = [],
        writes = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(base + '/platform/login');
    await page.locator('input[type=email]').fill('owner@platform.local');
    await page.locator('input[type=password]').fill(process.env.ASSET_TEST_PASSWORD ?? '123456');
    await page.locator('form button').click();
    await page.waitForURL('**/platform/tenants');
    const raw = await (await page.request.get(base + '/platform/settings/assets')).text();
    const pattern = /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/;
    const initial = JSON.parse(raw.match(pattern)[1]);
    let fixture;
    await page.route('**/*', async (route) => {
        const r = route.request(),
            url = new URL(r.url());
        if (url.origin !== base) return route.continue();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(r.method())) {
            writes.push(url.pathname);
            return route.abort();
        }
        if (url.pathname === '/__asset-admin-preview')
            return route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: raw.replace(
                    pattern,
                    () =>
                        `<script data-page="app" type="application/json">${JSON.stringify(fixture).replaceAll('<', '\\u003c')}</script>`,
                ),
            });
        return route.continue();
    });
    let checks = 0;
    for (const width of [375, 768, 1440])
        for (const locale of ['zh-CN', 'en'])
            for (const screen of ['settings', 'company', 'orders']) {
                fixture = structuredClone(initial);
                fixture.props.i18n.locale = locale;
                fixture.url = '/platform/settings/assets';
                if (screen === 'company') fixture.props.company = fixture.props.companies[0].id;
                if (screen === 'orders') {
                    fixture.component = 'platform/AssetOrders';
                    fixture.props.mode = 'withdrawal';
                    fixture.props.observations = [];
                    fixture.props.orders = {
                        data: [
                            {
                                id: 'preview-order',
                                tenant_id: 'preview-company',
                                company: 'Preview Company',
                                user_id: 'preview-user',
                                asset: 'ETH',
                                network: 'ETHEREUM',
                                amount: '0.123456789012345678',
                                fee: '0.001',
                                status: 'APPROVED',
                                created_at: new Date().toISOString(),
                                operator: 'Preview reviewer',
                                operated_at: new Date().toISOString(),
                                address: '0x1234…abcd',
                                tx_hash: '',
                            },
                        ],
                        next_page_url: null,
                        prev_page_url: null,
                    };
                }
                await page.setViewportSize({ width, height: 900 });
                await page.goto(base + '/__asset-admin-preview');
                await page.locator('h1').waitFor();
                await page.waitForTimeout(100);
                assert.ok(
                    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    `${width}/${locale}/${screen} overflow`,
                );
                if (locale === 'zh-CN')
                    await page.screenshot({
                        path: `${out}/${width}-admin-${screen}.png`,
                        fullPage: true,
                    });
                checks++;
            }
    assert.deepEqual(errors, []);
    assert.deepEqual(writes, []);
    await writeFile(out + '/admin-report.json', JSON.stringify({ checks, errors, writes }));
    console.log(JSON.stringify({ checks, errors, writes }));
} finally {
    await browser.close();
}
