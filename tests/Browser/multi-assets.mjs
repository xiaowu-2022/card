import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

// Financial/configuration writes are blocked. Enabled rails and balances below are browser-only fixtures.
const base = 'http://a.localhost:8000';
const output = process.env.ASSET_PREVIEW_DIR ?? '/tmp/card-multi-assets';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const context = await browser.newContext({ permissions: ['local-network-access'] });
    const page = await context.newPage();
    const errors = [];
    const writes = [];
    page.on('console', (m) => {
        if (m.type() === 'error') console.log('CONSOLE', m.text());
    });
    page.on('requestfailed', (r) => console.log('FAILED', r.url(), r.failure()));
    page.on('pageerror', (e) => {
        errors.push(e.message);
        console.log('PAGE ERROR', e.message);
    });
    await page.goto(base + '/login');
    await page.locator('#identifier').fill('user@a.localhost');
    await page.locator('#password').fill(process.env.ASSET_TEST_PASSWORD ?? '123456');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/dashboard');
    const raw = await (await page.request.get(base + '/dashboard')).text();
    const pattern = /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/;
    const match = raw.match(pattern);
    assert.ok(match, 'Inertia document');
    const initial = JSON.parse(match[1]);
    assert.equal(initial.props.assetOverview.assets.length, 4);
    await page.screenshot({ path: output + '/actual-375.png', fullPage: true });
    let fixture;
    let locale = 'zh-CN';
    let screen = 'assets';
    const overview = {
        estimate: '12842.34657812',
        updatedAt: new Date().toISOString(),
        assets: ['USDT', 'USDC', 'ETH', 'BTC'].map((asset, i) => ({
            asset,
            available: ['8462.34657812', '1200.123456', '0.812345678901234567', '0.02456789'][i],
            held: i === 0 ? '80' : '0',
            deposit: i === 0 ? '300' : '0',
            commission: i === 0 ? '620' : '0',
            exchange: i > 0,
            transfer: i === 0,
            activity: [
                {
                    id: 'activity-' + i,
                    amount: i === 2 ? '0.000000000000000001' : '25.123456',
                    time: new Date().toISOString(),
                    kind: 'Top up',
                },
            ],
            orders: [],
            rails: [
                ...(i === 0
                    ? [
                          {
                              code: 'USDT_TRON',
                              network: 'TRON',
                              deposit: true,
                              withdrawal: true,
                              fee: null,
                              minimum: '10',
                          },
                      ]
                    : []),
                {
                    code: asset === 'BTC' ? 'BTC_BITCOIN' : asset + '_ETHEREUM',
                    network: asset === 'BTC' ? 'BITCOIN' : 'ETHEREUM',
                    deposit: true,
                    withdrawal: true,
                    fee: asset === 'ETH' ? '0.002' : '1',
                    minimum: '0.000001',
                },
            ],
        })),
    };
    function make(screen, locale) {
        const f = structuredClone(initial);
        f.props.i18n.locale = locale;
        f.props.i18n.enabledLocales = ['en', 'zh-CN', 'ms', 'es'];
        f.props.errors = {};
        f.props.flash = {};
        f.url = screen === 'assets' ? '/dashboard' : '/assets/operate';
        if (screen === 'assets') {
            f.component = 'user/Dashboard';
            f.props.assetOverview = structuredClone(overview);
            f.props.cardOverview = {
                count: 1,
                pending: 0,
                items: [{ id: 'preview-card', last4: '3147', balance: '50.00', state: '正常' }],
            };
        } else {
            f.component = 'user/AssetFlow';
            f.props.overview = structuredClone(overview);
            f.props.mode =
                screen === 'exchange'
                    ? 'exchange'
                    : screen === 'withdrawal'
                      ? 'withdrawal'
                      : 'deposit';
            f.props.selectedAsset =
                screen === 'exchange' || screen === 'withdrawal' ? 'ETH' : 'USDT';
            f.props.result =
                screen === 'exchange'
                    ? {
                          id: 'preview-quote',
                          asset: 'ETH',
                          amount: '0.1',
                          state: 'Review exchange',
                          rate: '2500.123456789123456789',
                          fee: '0.25001235',
                          receive: '249.76233332',
                          expiresAt: new Date(Date.now() + 30000).toISOString(),
                          canConfirm: true,
                      }
                    : null;
        }
        return f;
    }
    await page.route('**/*', async (route) => {
        const r = route.request(),
            url = new URL(r.url());
        if (url.origin !== base) return route.continue();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(r.method())) {
            writes.push(url.pathname);
            return route.abort();
        }
        if (url.pathname === '/__asset-preview' || url.pathname === '/assets/operate') {
            if (url.pathname === '/assets/operate') {
                fixture = make(screen, locale);
                fixture.props.selectedAsset =
                    url.searchParams.get('asset') ?? fixture.props.selectedAsset;
            }
            if (r.headers()['x-inertia'])
                return route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify(fixture),
                });
            const html = raw.replace(
                pattern,
                () =>
                    `<script data-page="app" type="application/json">${JSON.stringify(fixture).replaceAll('<', '\\u003c')}</script>`,
            );
            return route.fulfill({ status: 200, contentType: 'text/html', body: html });
        }
        return route.continue();
    });
    const checks = [];
    for (const width of [375, 768, 1440])
        for (locale of ['zh-CN', 'en', 'ms', 'es'])
            for (screen of ['assets', 'deposit', 'exchange']) {
                await page.setViewportSize({ width, height: 900 });
                fixture = make(screen, locale);
                await page.goto(base + '/__asset-preview?screen=' + screen);
                await page
                    .locator('main')
                    .waitFor({ timeout: 10000 })
                    .catch(async (e) => {
                        console.log((await page.locator('body').innerText()).slice(0, 1000));
                        console.log('fixture', fixture.component, fixture.props.i18n);
                        throw e;
                    });
                await page.waitForTimeout(120);
                assert.ok(
                    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    `${width}/${locale}/${screen} overflow`,
                );
                assert.equal(await page.locator('input[type=password]').count(), 0);
                if (locale === 'zh-CN')
                    await page.screenshot({
                        path: `${output}/${width}-${screen}.png`,
                        fullPage: true,
                    });
                if (screen === 'assets') {
                    const tabs = page.locator('.user-asset-account');
                    const shortcut = page.locator('main nav a').first();
                    const shortcutBounds = await shortcut.boundingBox();
                    const accountBounds = await tabs.first().boundingBox();
                    assert.ok(shortcutBounds.y + shortcutBounds.height < accountBounds.y);
                    const circle = await shortcut.locator('span').first().boundingBox();
                    assert.equal(circle.width, circle.height);
                    assert.equal(await page.locator('[data-account-detail]').count(), 0);
                    assert.equal(await tabs.count(), 6);
                    assert.deepEqual(
                        await tabs.evaluateAll((nodes) =>
                            nodes.slice(2).map((node) => node.getAttribute('aria-label')),
                        ),
                        ['USDT', 'USDC', 'ETH', 'BTC'],
                    );
                    const panel = page.locator('[data-account-detail]');
                    for (const [index, href, amount, name] of [
                        [0, '/security-deposit', '300', 'security-deposit'],
                        [1, '/promotion', '620', 'commission'],
                    ]) {
                        await tabs.nth(index).click();
                        await page.getByRole('dialog').waitFor();
                        assert.equal(await panel.locator('a').getAttribute('href'), href);
                        assert.ok((await panel.innerText()).includes(amount));
                        assert.equal(await page.locator('a[href$="/activity"]').count(), 0);
                        assert.ok(
                            await page.evaluate(
                                () => document.documentElement.scrollWidth <= innerWidth,
                            ),
                        );
                        if (locale === 'zh-CN')
                            await page.screenshot({
                                path: `${output}/${width}-${name}.png`,
                                fullPage: true,
                            });
                        await page.keyboard.press('Escape');
                        await page.getByRole('dialog').waitFor({ state: 'hidden' });
                    }
                    await tabs.nth(2).click();
                    assert.ok((await panel.innerText()).includes('8462.34657812'));
                    await page.keyboard.press('Escape');
                    await page.getByRole('dialog').waitFor({ state: 'hidden' });
                    await tabs
                        .first()
                        .locator('..')
                        .locator('..')
                        .getByRole('button', { name: /更多|More|Lagi|Más/ })
                        .click();
                    assert.equal(await page.getByRole('dialog').locator('button').count(), 7);
                    if (locale === 'zh-CN')
                        await page.screenshot({
                            path: `${output}/${width}-accounts.png`,
                            fullPage: true,
                        });
                    await page.keyboard.press('Escape');
                    await page.getByRole('dialog').waitFor({ state: 'hidden' });
                    assert.equal(await page.locator('a[href="/wallet/transfer"]').count(), 1);
                    assert.equal(
                        await page
                            .getByText(
                                /处理中金额|Processing amount|Amaun dalam proses|Importe en proceso/,
                            )
                            .count(),
                        0,
                    );
                }
                checks.push({ width, locale, screen });
            }
    // Verify bottom-sheet selection and exact withdrawal preview without submitting funds.
    locale = 'zh-CN';
    screen = 'deposit';
    fixture = make(screen, locale);
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(base + '/__asset-preview');
    await page.getByRole('button', { name: '选择网络', exact: true }).click();
    await page.getByRole('dialog').waitFor();
    await page.screenshot({ path: output + '/375-networks.png', fullPage: true });
    await page.getByRole('button', { name: 'Ethereum (ERC20)', exact: true }).click();
    await page.getByRole('button', { name: 'USDT', exact: true }).click();
    await page.screenshot({ path: output + '/375-currencies.png', fullPage: true });
    await page.getByRole('button', { name: 'USDC', exact: true }).click();
    await page.getByRole('button', { name: '选择网络', exact: true }).waitFor();
    assert.equal(await page.getByRole('textbox').inputValue(), '');
    screen = 'withdrawal';
    fixture = make(screen, locale);
    await page.goto(base + '/__asset-preview');
    await page.getByRole('button', { name: '选择网络', exact: true }).click();
    await page.getByRole('button', { name: 'Ethereum', exact: true }).click();
    await page.getByRole('textbox').nth(0).fill('0.1');
    await page
        .getByRole('textbox')
        .nth(1)
        .fill('0x' + '2'.repeat(40));
    await page.getByRole('button', { name: '核对提现', exact: true }).click();
    await page.getByText('实际到账: 0.098 ETH').waitFor();
    await page.screenshot({ path: output + '/375-withdrawal.png', fullPage: true });
    assert.deepEqual(writes, [], 'No financial mutation reached the server');
    assert.deepEqual(errors, []);
    await writeFile(
        output + '/report.json',
        JSON.stringify(
            {
                checks: checks.length,
                consoleErrors: errors,
                financialWrites: writes,
                fixtures: 'browser-only',
                results: checks,
            },
            null,
            2,
        ),
    );
    console.log(JSON.stringify({ checks: checks.length, errors: 0, financialWrites: 0, output }));
} finally {
    await browser.close();
}
