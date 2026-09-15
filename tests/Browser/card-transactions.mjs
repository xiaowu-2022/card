import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Read-only live UI check plus browser-only response fixtures. No live provider
// calls, card creation, credentials changes, policy edits or money mutations.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ locale: 'zh-CN' });
const page = await context.newPage();
const base = 'http://a.localhost:8000';
const output = '/tmp/card-transactions-browser';
await mkdir(output, { recursive: true });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
await page.route('**/*', route => {
    const request = route.request();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && new URL(request.url()).pathname !== '/login') {
        errors.push('Unexpected write');
        return route.abort();
    }
    return route.continue();
});
let checks = 0;
async function inspect(name) {
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
        await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
        checks++;
    }
}
try {
    await page.goto(`${base}/login`);
    await page.locator('input[type=email]').fill('user@a.localhost');
    await page.locator('input[type=password]').fill('123456');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/dashboard');
    await page.goto(`${base}/cards`);
    await page.locator('#all-card-transactions').waitFor();
    await inspect('live');
    await page.goto(`${base}/account`);

    const cardA = '11111111-1111-4111-8111-111111111111';
    const cardB = '22222222-2222-4222-8222-222222222222';
    const fixtureCard = { productName: 'U Card', maskedPan: '•••• 1234', last4: '1234', expiry: '08/29', currency: 'USD', balance: '20.00000000' };
    let empty = false;
    await page.route('**/cards', async route => {
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.cards = empty ? [] : [{ ...fixtureCard, id: cardA }, { ...fixtureCard, id: cardB, last4: '5678', maskedPan: '•••• 5678' }];
        data.props.providerAvailable = true;
        data.props.i18n.locale = 'zh-CN';
        await route.fulfill({ response, json: data });
    });
    const calls = [];
    let failedOnce = false;
    const row = (id, cardId, date, amount, merchant, state = 'completed') => ({ id: id.repeat(64), cardId, last4: cardId === cardA ? '1234' : '5678', occurredAt: date, amount, merchant, currency: 'USD', type: 'purchase', state });
    const first = row('a', cardA, '2026-09-11T09:00:00', '123456789012.12345678', 'Example shop', 'pending');
    const second = row('b', cardB, '2026-09-11T10:00:00', '20.00000000', '<script>unsafe()</script> Shop');
    const third = row('c', cardA, '2026-09-10T10:00:00', '0.00000001', null);
    await page.route('**/cards/*/transactions?*', async route => {
        const url = new URL(route.request().url());
        const id = url.pathname.split('/')[2], current = Number(url.searchParams.get('page'));
        assert.ok([cardA, cardB].includes(id));
        calls.push([id, current]);
        if (id === cardB && !failedOnce) {
            failedOnce = true;
            return route.fulfill({ status: 503, json: { message: 'Unavailable' } });
        }
        const items = id === cardB ? [second] : current === 1 ? [first] : [{ ...first, state: 'completed' }, third];
        await route.fulfill({ json: { items, page: current, hasMore: id === cardA && current === 1 } });
    });
    await page.locator('a[href="/cards"]').first().click();
    await page.getByRole('button', { name: '重试', exact: true }).waitFor();
    await page.getByText('Example shop', { exact: true }).waitFor();
    await page.getByRole('button', { name: '重试', exact: true }).click();
    await page.getByText('<script>unsafe()</script> Shop', { exact: true }).waitFor();
    await page.getByRole('button', { name: '加载更多流水', exact: true }).click();
    await page.getByText('0.00000001 USD', { exact: true }).waitFor();
    const rows = page.locator('.user-card-transactions .divide-y > div');
    assert.equal(await rows.count(), 3);
    assert.match(await rows.nth(0).textContent(), /5678/);
    assert.equal(await page.locator('.user-card-transactions script').count(), 0);
    assert.equal(await page.getByRole('button', { name: '加载更多流水', exact: true }).count(), 0);
    assert.deepEqual(calls.filter(([id]) => id === cardB), [[cardB, 1], [cardB, 1]]);
    await inspect('aggregate');

    await page.goto(`${base}/account`);
    empty = true;
    await page.locator('a[href="/cards"]').first().click();
    await page.getByText('暂无卡片交易流水', { exact: true }).waitFor();
    await inspect('empty');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, paginationAndRetry: true, screenshots: output }));
} finally {
    await browser.close();
}
