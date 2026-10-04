import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json')),
);
const origin = process.env.STOCK_PREVIEW_ORIGIN ?? 'http://127.0.0.1:5217';
mkdirSync('artifacts/uni-parity/stock-versions', { recursive: true });
for (const [name, engine, options] of [
    ['chromium', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        for (const version of ['partner', 'standard', 'unavailable']) {
            const context = await browser.newContext({
                viewport: { width: 390, height: 844 },
                isMobile: true,
            });
            const page = await context.newPage();
            const errors = [],
                mutations = [];
            const dto = structuredClone(fixture.pages['/promotion/stock']);
            const partner = version !== 'standard';
            Object.assign(dto.props.report, {
                version: partner ? 'partner' : 'standard',
                accountBalance: null,
                stock: version === 'unavailable' ? null : '800.10000000',
                missingRates: version === 'unavailable' ? 1 : 0,
                cashFlow: partner
                    ? {
                          rateObservedAt: new Date().toISOString(),
                          assets: [
                              {
                                  asset: 'ETH',
                                  inflow: '0.5',
                                  outflow: '0.2',
                                  rate: version === 'unavailable' ? null : '2000',
                                  inflowUsdt: '1000',
                                  outflowUsdt: '400',
                              },
                          ],
                      }
                    : null,
                ...(partner
                    ? {
                          totals: {
                              inflow: '1220.10000000',
                              outflow: '420.00000000',
                              advances: '0',
                          },
                          trends: {},
                      }
                    : {}),
            });
            page.on('pageerror', (e) => errors.push(e.message));
            await context.route('**/*', (route) => {
                const req = route.request(),
                    u = new URL(req.url());
                if (u.pathname.startsWith('/api/v1')) {
                    if (req.method() !== 'GET') {
                        mutations.push(u.pathname);
                        return route.fulfill({ json: {} });
                    }
                    const key = u.pathname.slice(7);
                    if (key === '/bootstrap')
                        return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                    if (key === '/client/promotion/stock') {
                        const result = structuredClone(dto);
                        const flow = u.searchParams.get('flow');
                        if (flow) {
                            const current = Number(u.searchParams.get('flow_page') || 1);
                            result.props.report.flowDetails = {
                                direction: flow,
                                page: current,
                                total: 21,
                                hasMore: current === 1,
                                items: [
                                    {
                                        id: 'fixture-' + current,
                                        source: 'fixture',
                                        account_id: current === 1 ? 'MEMBER-002' : 'MEMBER-003',
                                        email: 'descendant.with.long.email@example.test',
                                        direct_account_id: 'BRANCH-001',
                                        direct_email: 'direct.branch@example.test',
                                        asset_code: 'ETH',
                                        amount: '0.123456789123456789',
                                        amountUsdt:
                                            version === 'unavailable' ? null : '246.91357825',
                                        posted_at: new Date().toISOString(),
                                    },
                                ],
                            };
                        }
                        return route.fulfill({ json: result });
                    }
                    if (key === '/unread')
                        return route.fulfill({ json: { messages: 0, support: 0 } });
                    return route.fulfill({ json: fixture.api[key] ?? {} });
                }
                if (u.origin !== origin || req.method() !== 'GET') return route.abort();
                return route.continue();
            });
            await page.goto(origin + '/#/pages/screen/index?path=%2Fpromotion%2Fstock');
            await page
                .getByText(partner ? 'Partner version' : 'Standard version', { exact: true })
                .waitFor();
            const content = await page.locator('.stock').innerText();
            assert.equal(
                content.includes('Your own deposits and withdrawals are excluded'),
                partner,
            );
            assert.equal(content.includes('Activation commissions paid'), !partner);
            assert.equal(
                content.includes('Current exchange rates are unavailable'),
                version === 'unavailable',
            );
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth > window.innerWidth + 1,
                ),
                false,
            );
            await page.screenshot({
                path: `artifacts/uni-parity/stock-versions/${name}-${version}.png`,
                fullPage: true,
            });
            if (partner) {
                for (const [index, title] of [
                    [0, 'Team deposit details'],
                    [1, 'Team withdrawal details'],
                ]) {
                    await page.locator('.flow-link').nth(index).click();
                    const list = page.locator('.flow-details');
                    await list.waitFor();
                    assert.ok((await list.innerText()).includes('BRANCH-001'));
                    assert.ok(
                        (await list.innerText()).includes(
                            'descendant.with.long.email@example.test',
                        ),
                    );
                    assert.ok((await list.innerText()).includes('0.123456789123456789 ETH'));
                    await page.getByText(title, { exact: true }).waitFor();
                    if (version === 'unavailable')
                        assert.ok((await list.innerText()).includes('Incomplete valuation'));
                    await list.getByText('Next', { exact: true }).click();
                    await page
                        .getByText('Transaction member: MEMBER-003', { exact: true })
                        .waitFor();
                    await page.setViewportSize({ width: 320, height: 740 });
                    assert.equal(
                        await page.evaluate(
                            () => document.documentElement.scrollWidth > innerWidth + 1,
                        ),
                        false,
                    );
                    await list
                        .locator('.report-pagination')
                        .evaluate((el) => el.scrollIntoView({ block: 'center' }));
                    await page.waitForTimeout(100);
                    await page.screenshot({
                        path: `artifacts/uni-parity/stock-versions/${name}-${version}-${index}-details.png`,
                        fullPage: true,
                    });
                    await page.locator('[aria-label="Back"]').last().click();
                    await page.locator('.stock').waitFor();
                }
            } else assert.equal(await page.locator('.flow-link').count(), 0);
            assert.deepEqual(errors, []);
            assert.ok(mutations.every((p) => p === '/api/v1/wallet/ensure'));
            await context.close();
        }
        console.log('PASS ' + name + ': partner, standard, unavailable rates; no business writes');
    } finally {
        await browser.close();
    }
}
