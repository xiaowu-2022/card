import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { writeFileSync, readFileSync, mkdirSync } from 'node:fs';
const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
const entry = manifest['resources/js/app.tsx'];
// Run against existing local seeded records after npm run build. Never submit business forms.
const base = 'http://admin.localhost:8000',
    out = 'artifacts/uni-parity/admin-review';
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } }),
    page = await ctx.newPage();
page.setDefaultTimeout(20000);
page.setDefaultNavigationTimeout(20000);
const errors = [],
    blocked = [];
page.on('pageerror', (e) => errors.push(e.message));
await ctx.route('**/*', async (r) => {
    const u = new URL(r.request().url()),
        m = r.request().method();
    if (u.origin !== base) return r.abort();
    if (u.pathname === '/platform/card-transaction-batches' && m === 'GET')
        return r.fulfill({ json: { items: [] } });
    if (!['GET', 'HEAD'].includes(m) && u.pathname != '/platform/login') {
        blocked.push(u.pathname);
        return r.abort();
    }
    if (r.request().isNavigationRequest() && m === 'GET') {
        // Provider editor uses a UI fixture; opening this test never queries a real provider.
        const response = await r.fetch(
            u.pathname === '/platform/card-providers' ? { url: base + '/platform/users' } : {},
        );
        let html = await response.text();
        if (u.pathname === '/platform/card-providers')
            html = html.replace(
                /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/,
                (_, raw) => {
                    const initial = JSON.parse(raw);
                    initial.component = 'platform/CardProviders';
                    initial.props.providers = {
                        data: [],
                        total: 0,
                        current_page: 1,
                        last_page: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    };
                    return (
                        '<script data-page="app" type="application/json">' +
                        JSON.stringify(initial).replaceAll('<', '\\u003c') +
                        '</script>'
                    );
                },
            );
        html = html.replace(/<script\b[^>]*type="module"[^>]*>[\s\S]*?<\/script>/g, '');
        // UI-only permission fixture. Business requests remain blocked.
        if (u.pathname === '/platform/users')
            html = html.replace(
                /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/,
                (_, raw) => {
                    const initial = JSON.parse(raw);
                    initial.props.canChangeReferrer = true;
                    initial.props.canAdjustCommission = true;
                    return (
                        '<script data-page="app" type="application/json">' +
                        JSON.stringify(initial).replaceAll('<', '\\u003c') +
                        '</script>'
                    );
                },
            );
        html = html.replace(
            '</head>',
            (entry.css ?? [])
                .map((f) => '<link rel="stylesheet" href="/build/' + f + '">')
                .join('') +
                '<script type="module" src="/build/' +
                entry.file +
                '"></script></head>',
        );
        return r.fulfill({ response, body: html });
    }
    return r.continue();
});
try {
    await page.goto(base + '/platform/login');
    await page.locator('input[type=email]').fill('owner@platform.local');
    await page
        .locator('input[type=password]')
        .fill(process.env.ADMIN_LAYOUT_TEST_PASSWORD ?? '123456');
    await page.locator('form button').last().click();
    await page.waitForURL('**/platform/tenants');

    const checks = [];
    for (const width of [1024, 1366, 1920]) {
        await page.setViewportSize({ width, height: 768 });
        for (const path of ['users', 'cards?tab=cards', 'topups']) {
            await page.goto(base + '/platform/' + path);
            await page.locator('main table').waitFor();
            const row = page.locator('main tbody tr').first(),
                actions = row.locator('td').last();
            const table = page.locator('main table'),
                scroll = table.locator('..');
            const geometry = () =>
                actions.evaluate((el) => {
                    const r = el.getBoundingClientRect(),
                        parent = el.closest('table').parentElement.getBoundingClientRect();
                    return {
                        right: r.right,
                        edge: parent.right,
                        height: r.height,
                        position: getComputedStyle(el).position,
                    };
                });
            let box = await geometry();
            assert.equal(box.position, 'sticky');
            assert.ok(Math.abs(box.right - box.edge) < 2);
            await scroll.evaluate((el) => {
                el.scrollLeft = el.scrollWidth;
            });
            box = await geometry();
            assert.ok(Math.abs(box.right - box.edge) < 2);
            assert.ok(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            );
            if (path.startsWith('cards')) {
                assert.ok(box.height < 110, `Card row too tall: ${box.height}`);
                const more = row.getByRole('button', { name: '更多操作', exact: true });
                for (const title of ['查看卡片信息', '可用额度上限', '登记溢出消费']) {
                    await more.click();
                    await page.getByRole('menuitem', { name: title, exact: true }).click();
                    const dialog = page.getByRole('dialog');
                    await dialog.waitFor();
                    assert.ok(
                        await dialog.getByRole('heading', { name: title, exact: true }).isVisible(),
                    );
                    assert.ok(
                        await dialog.evaluate((e) => {
                            const r = e.getBoundingClientRect();
                            return r.top >= 15 && r.bottom <= innerHeight - 15;
                        }),
                    );
                    await page.keyboard.press('Escape');
                    await dialog.waitFor({ state: 'hidden' });
                    await page.waitForTimeout(50);
                    assert.equal(
                        await more.evaluate((e) => e === document.activeElement),
                        true,
                        JSON.stringify({
                            title,
                            focus: await page.evaluate(() =>
                                document.activeElement?.tagName,
                            ),
                        }),
                    );
                    assert.equal(
                        await page.evaluate(() => getComputedStyle(document.body).pointerEvents),
                        'auto',
                    );
                }
            }
            if (path === 'users') {
                await row.getByRole('button', { name: '更多操作', exact: true }).click();
                for (const name of ['查看实名认证', '钱包调整', '修改推荐人', '手动佣金']) {
                    const item = page.getByRole('menuitem', { name, exact: true });
                    // Permission-specific links must remain available in the consolidated menu.
                    assert.equal(await item.count(), 1, name);
                }
                await page.keyboard.press('Escape');
            }
            await scroll.evaluate((el) => {
                el.scrollLeft = 0;
            });
            await page.screenshot({
                path: out + '/after-' + path.replaceAll(/[^a-z0-9]/g, '-') + '-' + width + '.png',
            });
            checks.push({ path, width, rowHeight: box.height });
        }
    }
    await page.setViewportSize({ width: 1366, height: 400 });
    await page.goto(base + '/platform/card-providers');
    await page.getByRole('button', { name: '新增卡商', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    assert.ok(
        await dialog.evaluate((e) => {
            const r = e.getBoundingClientRect();
            return (
                r.top >= 15 &&
                r.bottom <= innerHeight - 15 &&
                getComputedStyle(e).overflowY === 'auto'
            );
        }),
    );
    await dialog.evaluate((e) => {
        e.scrollTop = e.scrollHeight;
    });
    await page.screenshot({ path: out + '/after-short-dialog.png' });
    await page.keyboard.press('Escape');
    await page.goto(base + '/platform/settings/assets');
    await page.waitForTimeout(200);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(100);
    assert.ok(await page.locator('main .sticky').evaluate(el => {
        const header = document.querySelector('header').getBoundingClientRect();
        return el.getBoundingClientRect().top >= header.bottom;
    }));
    assert.deepEqual(errors, []);
    assert.deepEqual(blocked, []);
    console.log(JSON.stringify({ checks, errors, blocked }));
    writeFileSync(out + '/after.json', JSON.stringify({ checks, errors, blocked }, null, 2));
} finally {
    await browser.close();
}
