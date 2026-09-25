// Offline UI acceptance: every request is intercepted; no server, database or funds are touched.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const entry = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'))['resources/js/app.tsx'];
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const errors = [];
const ids = [1, 2, 3, 4].map(n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`);
const totals = { total: '123456789123.12345678', annual: '123456789100', activation: '23.12345678', legacy: '0' };
const identity = n => ({ id: n < 0 ? null : ids[n], accountId: `20260925000${n + 1}`, displayName: n === 0 ? '测试成员 LongNicknameWithoutSpacesABCDEFGHIJKLMNOPQRSTUVWXYZ' : `成员 Member ${n}` });
const member = n => ({ ...identity(n), rank: n, maskedEmail: 'm***@averylongexampledomain.test', relation: 'direct', joinedAt: '2026-09-24T12:00:00Z', endsAt: n === 3 ? '2027-09-24T12:00:00Z' : null, depositAmount: '300', teamSize: n === 0 ? 4 : 0, membershipStatus: n === 0 ? 'inactive' : n === 1 ? 'ordinary' : 'agent', totals: n === 0 ? { total: '50', annual: '0', activation: '50', legacy: '0' } : n === 1 ? { total: '0', annual: '0', activation: '0', legacy: '0' } : totals });
const period = { ranks: [0, 1, 9], dateFrom: null, dateTo: null, today: '2026-09-25', timezone: 'Asia/Kuala_Lumpur', presets: { 1: '2026-09-25', 7: '2026-09-19', 30: '2026-08-27' } };
try {
    for (const locale of ['zh-CN', 'en', 'ms', 'es']) {
        const page = await browser.newPage({ viewport: { width: 768, height: 1000 }, locale });
        page.on('pageerror', e => errors.push(e.message));
        let calls = 0, failNext = false, emptyNext = false;
        const summaryRequests = [];
        function fixture(url) {
            const subjectId = url.searchParams.get('subject');
            const n = subjectId ? ids.indexOf(subjectId) : -1;
            const subject = identity(n);
            const breadcrumbs = n < 0 ? [] : Array.from({ length: n + 1 }, (_, i) => identity(i));
            const filters = Object.fromEntries(url.searchParams);
            delete filters.scope;
            const props = {
                errors: {}, flash: {}, i18n: { locale, enabledLocales: ['zh-CN', 'en', 'ms', 'es'], timezone: period.timezone, surface: 'user' },
                tenant: { id: 'preview', name: 'Preview', branding: { brandName: 'Preview', primaryColor: '#39AD8D', logoUrl: null }, locales: ['zh-CN', 'en', 'ms', 'es'] },
                auth: { admin: null, user: { id: 'preview', accountId: identity(-1).accountId, displayName: 'Preview', email: null, phone: null, status: 'ACTIVE' } },
            };
            let component = 'user/PromotionReport';
            if (url.pathname === '/promotion/commissions') {
                component = 'user/PromotionCommissions';
                props.history = { ...period, subject, breadcrumbs, filters, totals, page: 1, hasMore: false, items: [] };
            } else {
                let items = n < 0 ? [member(0), member(3)] : n === 0 ? [member(1)] : [];
                if (n < 0 && filters.account_id) items.push({ ...member(1), relation: 'indirect' });
                if (filters.account_id) items = items.filter(row => row.accountId.includes(filters.account_id));
                props.section = 'direct';
                props.report = { ...period, subject, breadcrumbs, subjectTotals: totals, memberCounts: { direct: n < 0 ? 2 : n === 0 ? 1 : 0, total: n < 0 ? 3 : n === 0 ? 1 : 0 }, filters, page: Number(filters.page || 1), hasMore: n < 0 && !filters.page, total: items.length, items };
            }
            return { component, props, url: url.pathname + url.search, version: 'preview' };
        }
        await page.route('**/*', async route => {
            const req = route.request(), url = new URL(req.url());
            assert.equal(req.method(), 'GET', 'Unexpected mutation');
            if (url.pathname.endsWith('/team-summary')) {
                calls++; summaryRequests.push(url.searchParams.get('subject'));
                if (failNext) { failNext = false; return route.fulfill({ status: 503, json: { message: 'Offline test failure' } }); }
                if (emptyNext) { emptyNext = false; return route.fulfill({ json: { totalMembers: 0, rows: [] } }); }
                await new Promise(resolve => setTimeout(resolve, 100));
                return route.fulfill({ json: { totalMembers: 4, rows: [
                    { rank: 0, direct: 1, indirect: 1, annual: '0', activation: '50' },
                    { rank: 9, direct: 1, indirect: 1, annual: totals.total, activation: '0' },
                ] } });
            }
            if (url.pathname.startsWith('/promotion/')) {
                const data = fixture(url);
                if (req.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: data });
                return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html lang="${locale}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script type="application/json" data-page="app">${JSON.stringify(data)}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
            }
            if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/')) {
                const file = path.join(root, 'public', url.pathname);
                if (!fs.existsSync(file)) return route.abort();
                return route.fulfill({ path: file, contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png' });
            }
            return route.abort();
        });
        await page.goto('http://team-preview.test/promotion/direct');
        const first = page.locator('.report-member').first();
        await first.waitFor();
        assert.equal(calls, 0);
        assert((await first.locator('.report-member-team-count').innerText()).includes('4'));
        assert((await page.locator('.report-member').nth(1).locator('.report-member-team-count').innerText()).includes('0'));
        assert.equal(await page.locator('.team-scope-switch').count(), 0);
        assert.equal(await first.locator('.report-member-relation, .report-member-meta, .report-member-income, .member-detail-toggles').count(), 0);
        const trigger = first.locator('.member-data-button');
        const dialog = page.getByRole('dialog');
        const close = () => dialog.locator('button[aria-label]').last().click();
        for (const width of [320, 375, 479, 480, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${locale}/${width}: page overflow`);
            const left = await first.locator('.report-member-identity').boundingBox(), right = await first.locator('.report-member-financial').boundingBox();
            assert(right.x > left.x + left.width && Math.abs(right.y - left.y) < 1, 'Member blocks must remain side by side');
            assert((await trigger.boundingBox()).height >= 44, 'Data trigger must be easy to tap');
            assert((await first.locator('.report-member-person').boundingBox()).height >= 44, 'Team navigation must be easy to tap');
            assert(await first.locator('.report-account').evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 14));
            assert(await first.locator('.member-commission-summary strong').evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 18));
            if (locale === 'zh-CN' && [375, 768].includes(width)) await page.screenshot({ path: `/tmp/member-data-list-${width}.png`, fullPage: true });
        }
        await page.setViewportSize({ width: 375, height: 900 });
        const beforeHeight = (await first.boundingBox()).height;
        await trigger.click();
        await dialog.waitFor();
        assert.equal(await dialog.getByRole('tab').first().getAttribute('aria-selected'), 'true');
        assert.equal(calls, 0, 'Opening income must not fetch the team');
        assert((await dialog.locator('.member-data-identity').innerText()).includes(identity(0).displayName));
        assert.equal(await dialog.locator('.member-status-inactive').count(), 1);
        await dialog.getByRole('tab').nth(1).click();
        await dialog.getByRole('status').waitFor();
        await dialog.locator('.member-team-table').waitFor();
        assert.equal(calls, 1);
        for (const width of [320, 375, 479, 480, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const box = await dialog.boundingBox();
            assert(box.x >= -1 && box.y >= -1 && box.x + box.width <= width + 1, `${locale}/${width}: dialog outside viewport`);
            assert(box.height <= 900 * .85 + 1, 'Dialog must remain within 85dvh');
            if (width < 640) assert(Math.abs(box.y + box.height - 900) < 1, 'Phone dialog must attach to bottom');
            assert(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth), 'Dialog overflow');
            assert(await dialog.locator('.member-team-table').evaluate(el => el.scrollWidth <= el.clientWidth), 'Team table overflow');
            const headerY = (await dialog.locator('.member-data-header').boundingBox()).y;
            await dialog.locator('.member-data-scroll').evaluate(el => { el.scrollTop = el.scrollHeight; });
            assert.equal((await dialog.locator('.member-data-header').boundingBox()).y, headerY, 'Header must stay fixed while contents scroll');
            if (locale === 'zh-CN' && width === 375) await page.screenshot({ path: '/tmp/member-data-panel-375.png' });
        }
        await page.setViewportSize({ width: 375, height: 900 });
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        assert.equal((await first.boundingBox()).height, beforeHeight, 'Opening details must not elongate member rows');
        await page.waitForFunction(() => document.activeElement === document.querySelector('.member-data-button'));
        assert(await trigger.evaluate(el => document.activeElement === el), 'Closing must restore trigger focus');
        await page.keyboard.press('Enter');
        await dialog.waitFor();
        assert.equal(await dialog.getByRole('tab').first().getAttribute('aria-selected'), 'true', 'Reopening defaults to income');
        await dialog.getByRole('tab').nth(1).click();
        await dialog.locator('.member-team-table').waitFor();
        assert.equal(calls, 1, 'Reopening must reuse the cached team');
        await close();
        failNext = true;
        await page.locator('.report-member').nth(1).locator('.member-data-button').click();
        assert((await dialog.locator('.member-data-identity').innerText()).includes('2027'), 'Agent expiry must be available in details');
        await dialog.getByRole('tab').nth(1).click();
        await dialog.getByRole('alert').waitFor();
        await dialog.getByRole('alert').getByRole('button').click();
        await dialog.locator('.member-team-table').waitFor();
        assert.equal(calls, 3);
        await close();
        await page.locator('.promotion-report-page form input').fill('2026');
        await page.locator('.promotion-report-page form button[type=submit]').click();
        await page.waitForURL('**/promotion/direct?*account_id=2026*');
        await page.waitForFunction(() => document.querySelectorAll('.report-member').length === 3);
        await first.locator('.report-member-person').click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[0]}`);
        await page.waitForFunction(account => document.querySelector('.team-viewing-summary').textContent.includes(account), identity(0).accountId);
        await page.goBack();
        await page.waitForURL('**/promotion/direct?*account_id=2026*');
        await page.waitForFunction(() => document.querySelectorAll('.report-member').length === 3);
        assert.equal(await page.locator('.promotion-report-page form input').inputValue(), '2026');
        await page.goForward();
        await page.waitForURL(`**/promotion/direct?subject=${ids[0]}`);
        await page.waitForFunction(() => document.querySelectorAll('.report-member').length === 1);
        assert.equal(await first.locator('.member-commission-summary strong').innerText(), '0.00', 'Zero income must remain visible');
        emptyNext = true;
        await first.locator('.member-data-button').click();
        await dialog.getByRole('tab').nth(1).click();
        await page.waitForFunction(() => document.querySelector('[role="tabpanel"][data-state="active"]').getAttribute('aria-busy') === 'false');
        assert.equal(await dialog.locator('.member-team-table').count(), 0, 'Empty team has no table');
        assert.equal(summaryRequests.at(-1), ids[0]);
        assert.equal(calls, 4);
        await dialog.getByRole('tab').first().click();
        await dialog.locator('.member-commission-link').click();
        await page.waitForURL('**/promotion/commissions*');
        assert.equal(new URL(page.url()).searchParams.get('subject'), ids[0]);
        assert.equal(new URL(page.url()).searchParams.get('source_member'), ids[1]);
        await page.goBack(); await first.waitFor();
        await first.locator('.report-member-person').click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[1]}`);
        await page.locator('.report-empty').waitFor();
        assert.equal(await page.locator('.team-parent-link').count(), 0, 'Breadcrumbs replace the redundant parent link');
        for (const width of [320, 375, 479, 480, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            const layout = await page.locator('.team-breadcrumbs').evaluate(nav => {
                const root = nav.getBoundingClientRect();
                const items = [...nav.querySelectorAll('.team-breadcrumb-item')];
                return {
                    fits: nav.scrollWidth <= nav.clientWidth,
                    aligned: items.every(item => {
                        const [separator, label] = [...item.children].map(child => child.getBoundingClientRect());
                        return Math.abs(separator.y - label.y) < 1
                            && label.right <= root.right + 1;
                    }),
                };
            });
            assert(layout.fits && layout.aligned, `Breadcrumb alignment ${locale} ${width}`);
            if (locale === 'zh-CN' && width === 375) await page.screenshot({ path: '/tmp/team-breadcrumb-375.png', fullPage: true });
        }
        await page.locator('.team-breadcrumbs a').last().click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[0]}`);
        await first.waitFor();
        await page.locator('.team-breadcrumbs a').first().click();
        await page.waitForURL('**/promotion/direct?*account_id=2026*');
        assert.equal(await page.locator('.promotion-report-page form input').inputValue(), '2026');
        await page.goto('http://team-preview.test/promotion/direct');
        await first.waitFor();
        await page.locator('.promotion-report-page nav button').last().click();
        await page.waitForURL('**/promotion/direct?*page=2*');
        await page.locator('.member-sort').selectOption('commission_desc');
        await page.waitForURL(url => url.searchParams.get('sort') === 'commission_desc' && url.searchParams.get('page') === '1');
        await page.goBack();
        await page.waitForURL('**/promotion/direct?*page=2*');
        assert.equal(await page.locator('.member-sort').inputValue(), 'registered_desc');
        const search = page.locator('.promotion-report-page form').first();
        await search.locator('input').fill(identity(1).accountId);
        await search.locator('button[type="submit"]').click();
        await page.waitForURL(url => url.searchParams.get('account_id') === identity(1).accountId && url.searchParams.get('page') === '1');
        await page.waitForFunction(account => document.querySelector('.report-member-person').textContent.includes(account), identity(1).accountId);
        await search.locator('input').fill('');
        await search.locator('button[type="submit"]').click();
        await page.waitForURL(url => !url.searchParams.has('account_id'));
        await page.waitForFunction(() => document.querySelectorAll('.report-member').length === 2);
        await page.goto(`http://team-preview.test/promotion/direct?subject=${ids[0]}&account_id=2026&rank=0&funding=unfunded&page=4`);
        for (const sort of ['commission_asc', 'commission_desc', 'registered_asc', 'registered_desc']) {
            await page.locator('.member-sort').selectOption(sort);
            await page.waitForURL(url => url.searchParams.get('sort') === sort && url.searchParams.get('page') === '1');
            const params = new URL(page.url()).searchParams;
            assert.equal(params.get('subject'), ids[0]);
            assert.equal(params.get('account_id'), '2026');
            assert.equal(params.get('rank'), '0');
            assert.equal(params.get('funding'), 'unfunded');
        }
        const rootQuery = 'account_id=2026&rank=0&funding=unfunded&sort=commission_desc&page=4';
        const childQuery = `subject=${ids[0]}&account_id=2026&rank=1&funding=funded&sort=registered_asc&page=2`;
        const waitForState = async query => {
            const expected = new URLSearchParams(query);
            await page.waitForURL(url => [...expected].every(([key, value]) => url.searchParams.get(key) === value));
            assert.equal(await page.locator('.member-sort').inputValue(), expected.get('sort'));
            assert.equal(await page.locator('.promotion-report-page form input').inputValue(), '2026');
        };
        await page.goto(`http://team-preview.test/promotion/direct?${rootQuery}`);
        await first.locator('.report-member-person').click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[0]}`);
        assert.equal(await page.locator('.member-sort').inputValue(), 'registered_desc', 'Entering a descendant starts with its default list');
        await page.goto(`http://team-preview.test/promotion/direct?${childQuery}`);
        await first.locator('.report-member-person').click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[1]}`);
        await page.reload();
        await page.locator('.user-page-header a').click();
        await waitForState(childQuery);
        await first.locator('.report-member-person').click();
        await page.waitForURL(`**/promotion/direct?subject=${ids[1]}`);
        await page.locator('.team-breadcrumbs a').last().click();
        await waitForState(childQuery);
        await page.locator('.team-breadcrumbs a').first().click();
        await waitForState(rootQuery);
        await first.locator('.member-data-button').click();
        await dialog.locator('.member-commission-link').click();
        await page.waitForURL('**/promotion/commissions*');
        await page.locator('.user-page-header a').click();
        await waitForState(rootQuery);
        await page.close();
    }
    assert.deepEqual(errors, []);
    console.log('Passed 4 languages × 6 widths: drilldown, automatic descendant search, clearing, history, navigation, pagination, accessible modal tabs, lazy/cached/retry/empty panels, focus restoration and overflow.');
} finally { await browser.close(); }
