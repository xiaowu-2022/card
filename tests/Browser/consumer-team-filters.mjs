import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5238';
const out = 'artifacts/uni-parity/team-filters';
mkdirSync(out, { recursive: true });
for (const [name, engine, options] of [
    ['chromium', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        for (const language of ['zh-CN', 'en', 'ms', 'es']) {
            const context = await browser.newContext({
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            });
            const page = await context.newPage();
            const errors = [],
                mutations = [],
                requests = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await context.route('**/*', (route) => {
                const req = route.request(),
                    u = new URL(req.url());
                if (u.pathname.startsWith('/api/v1')) {
                    const key = u.pathname.slice(7);
                    if (req.method() !== 'GET') {
                        mutations.push(key);
                        return route.fulfill({ json: {} });
                    }
                    if (key === '/bootstrap')
                        return route.fulfill({
                            json: { ...fixture.authenticated, locale: language },
                        });
                    if (key.startsWith('/client/promotion/')) {
                        const path = key.slice(7);
                        const dto = structuredClone(fixture.pages[path]);
                        assert.ok(dto, path);
                        dto.props.i18n.locale = language;
                        if (path === '/promotion/direct') {
                            requests.push(u);
                            const r = dto.props.report;
                            r.filters = { sort: '', ...Object.fromEntries(u.searchParams) };
                            r.page = Number(u.searchParams.get('page') || 1);
                            r.hasMore = r.page === 1;
                            r.memberCounts = { direct: 100, total: 500 };
                            r.total = 100;
                            r.subjectTotals.total = '16880';
                            if (u.searchParams.has('subject'))
                                r.subject.id = u.searchParams.get('subject');
                            r.items =
                                u.searchParams.get('account_id') === '999'
                                    ? []
                                    : [
                                          {
                                              id: 'member-1',
                                              accountId: '202607303070',
                                              displayName: '测试成员 099',
                                              maskedEmail: 'p***@fixture.invalid',
                                              rank: 0,
                                              membershipStatus: 'inactive',
                                              joinedAt: '2026-07-30T00:00:00Z',
                                              endsAt: null,
                                              totals: {
                                                  total: '0',
                                                  annual: '0',
                                                  activation: '0',
                                                  legacy: '0',
                                              },
                                              relation: 'direct',
                                              depositAmount: '0',
                                              teamSize: 4,
                                          },
                                      ];
                            if (u.searchParams.get('account_id') === '999') r.total = 0;
                        }
                        return route.fulfill({ json: dto });
                    }
                    if (key === '/unread')
                        return route.fulfill({ json: { messages: 0, support: 0 } });
                    return route.fulfill({ json: fixture.api[key] ?? {} });
                }
                if (u.origin !== origin || req.method() !== 'GET') return route.abort();
                return route.continue();
            });
            const open = async (path) => {
                await page.goto(origin + '/#/pages/screen/index?path=' + encodeURIComponent(path));
                await page.locator('.report-page').waitFor();
            };
            const settle = async (action) => {
                await Promise.all([
                    page.waitForResponse((r) => r.url().includes('/client/promotion/direct')),
                    action(),
                ]);
                await page.locator('.member-search').waitFor();
                await page.waitForTimeout(350);
            };
            await open('/promotion/direct?funding=funded');
            for (const width of [320, 375, 390, 430]) {
                await page.setViewportSize({ width, height: 844 });
                const input = await page.locator('.member-search uni-input').boundingBox();
                const button = await page.locator('.member-search uni-button').boundingBox();
                await page.screenshot({ path: `${out}/${name}-${language}-${width}.png` });
                assert.ok(
                    Math.abs(input.y - button.y) < 1,
                    `${name}/${language}/${width}: search row`,
                );
                assert.equal(Math.round(input.height), 44);
                assert.equal(Math.round(button.height), 44);
                assert.ok(input.width > 100 && input.x + input.width <= button.x);
                assert.ok(button.x + button.width <= width);
                const filter = await page
                    .locator('.member-results-toolbar .report-filter-button')
                    .boundingBox();
                const count = await page.locator('.member-count-copy').boundingBox();
                assert.ok(count.x + count.width <= filter.x);
                assert.ok(Math.abs(count.y + count.height / 2 - filter.y - filter.height / 2) < 1);
                assert.equal(await page.locator('.report-page .select-field').count(), 0);
                assert.ok(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth <= window.innerWidth,
                    ),
                );
                await page.screenshot({ path: `${out}/${name}-${language}-${width}.png` });
            }
            await page.locator('.report-filter-button').click();
            const dialog = page.locator('[role="dialog"]');
            assert.equal(await dialog.locator('.select-field').count(), 2);
            assert.ok(!(await dialog.innerText()).includes('Please select'));
            await page.screenshot({ path: `${out}/${name}-${language}-dialog.png` });
            await dialog.locator('.modal-header uni-button').click();
            if (language === 'en') {
                // Pick one row below the current value through the actual touch wheel.
                const chooseNext = async (field) => {
                    await dialog.locator('uni-picker').nth(field).click();
                    const picker = page.locator('.uni-picker-container:visible');
                    await picker.waitFor();
                    await page.waitForTimeout(350);
                    const wheel = await picker.locator('uni-picker-view-column').boundingBox();
                    const row = await picker.locator('.uni-picker-view-indicator').boundingBox();
                    await page.touchscreen.tap(
                        wheel.x + wheel.width / 2,
                        wheel.y + wheel.height / 2 + row.height,
                    );
                    await page.waitForTimeout(700);
                    await picker.locator('.uni-picker-action-confirm').click();
                    await picker.waitFor({ state: 'hidden' });
                };
                await page.locator('.report-filter-button').click();
                assert.match(
                    await dialog.locator('uni-picker').nth(1).innerText(),
                    /Registration: newest first/,
                );
                await chooseNext(1);
                assert.match(
                    await dialog.locator('uni-picker').nth(1).innerText(),
                    /Registration: oldest first/,
                );
                const before = requests.length;
                await dialog.locator('.modal-header uni-button').click();
                assert.equal(requests.length, before, 'cancel does not apply');
                await page.locator('.report-filter-button').click();
                assert.match(
                    await dialog.locator('uni-picker').nth(1).innerText(),
                    /Registration: newest first/,
                );
                await chooseNext(0);
                await chooseNext(1);
                await settle(() => dialog.getByText('Apply filters', { exact: true }).click());
                assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_asc');
                assert.equal(requests.at(-1).searchParams.get('rank'), 'registered');
                assert.equal(requests.at(-1).searchParams.get('funding'), null);
                assert.equal(await page.locator('.report-chips uni-button').count(), 2);
                for (const term of [
                    '测试成员',
                    'Alice',
                    'Mixed+Tag@Example.test',
                    '50%_ &名字#?',
                    "O'Connor",
                    'a..b',
                ]) {
                    await page.locator('.member-search input').fill(term);
                    await settle(() => page.locator('.member-search uni-button').click());
                    assert.equal(requests.at(-1).searchParams.get('account_id'), term);
                    assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_asc');
                }
                await page.locator('.member-search input').fill('202607303070');
                await settle(() => page.locator('.member-search uni-button').click());
                assert.equal(requests.at(-1).searchParams.get('account_id'), '202607303070');
                assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_asc');
                await settle(() =>
                    page.locator('.report-pagination').getByText('Next', { exact: true }).click(),
                );
                assert.equal(requests.at(-1).searchParams.get('page'), '2');
                await settle(() => page.locator('.report-member-person').click());
                assert.equal(requests.at(-1).searchParams.get('subject'), 'member-1');
                await settle(() =>
                    page.locator('.breadcrumbs').getByText('My team', { exact: true }).click(),
                );
                assert.equal(requests.at(-1).searchParams.get('page'), '2');
                assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_asc');
                await settle(() =>
                    page
                        .locator('.report-chips uni-button')
                        .filter({ hasText: 'Registration: oldest first' })
                        .click(),
                );
                assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_desc');
                assert.equal(requests.at(-1).searchParams.get('page'), '1');
                await page.locator('.report-filter-button').click();
                await settle(() => dialog.getByText('Reset filters', { exact: true }).click());
                assert.equal(requests.at(-1).searchParams.get('sort'), 'registered_desc');
                assert.equal(requests.at(-1).searchParams.get('account_id'), null);
                assert.equal(requests.at(-1).searchParams.get('rank'), null);
                assert.equal(await page.locator('.report-chips').count(), 0);
                await page.locator('.report-filter-button').click();
                await chooseNext(0);
                await chooseNext(0);
                await settle(() => dialog.getByText('Apply filters', { exact: true }).click());
                assert.equal(requests.at(-1).searchParams.get('rank'), '0');
                await page
                    .locator('.report-chips')
                    .getByText('Ordinary member ×', { exact: true })
                    .waitFor();
                await page.locator('.report-filter-button').click();
                assert.match(
                    await dialog.locator('uni-picker').first().innerText(),
                    /Ordinary member/,
                );
                await settle(() => dialog.getByText('Reset filters', { exact: true }).click());
                await page.locator('.member-search input').fill('999');
                await settle(() => page.locator('.member-search uni-button').click());
                await page.getByText('No matching records.', { exact: true }).waitFor();
                for (const path of ['/promotion/daily', '/promotion/commissions']) {
                    await open(path);
                    assert.equal(await page.locator('.member-search').count(), 0);
                    await page.locator('.report-filter-button').click();
                    assert.equal(
                        await dialog.getByText('Member sorting', { exact: true }).count(),
                        0,
                    );
                    await dialog.locator('.modal-header uni-button').click();
                }
            }
            assert.deepEqual(errors, []);
            assert.ok(
                mutations.every((key) => key === '/wallet/ensure'),
                mutations.join(','),
            );
            console.log(
                `PASS ${name} ${language}: four viewport sizes, filter dialog${language === 'en' ? ', interactions and shared reports' : ''}`,
            );
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
