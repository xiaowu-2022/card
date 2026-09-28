import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')),
);
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5202';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const id = '11111111-1111-4111-8111-111111111111';
const out = 'artifacts/uni-parity/assets-acceptance';
mkdirSync(out, { recursive: true });
async function scenario(
    name,
    path,
    run,
    mutate = () => ({
        status: 503,
        json: { error: { message: 'Unable to load. Please try again.' } },
    }),
    prepare = () => {},
) {
    const context = await browser.newContext({ viewport: { width: 375, height: 900 } }),
        page = await context.newPage(),
        posts = [],
        errors = [];
    let dto = structuredClone(fixture.pages[path]);
    assert.ok(dto, path);
    dto.props.i18n.locale = 'en';
    const boot = structuredClone(dto.props.auth?.user ? fixture.authenticated : fixture.guest);
    boot.locale = 'en';
    if (name === 'withdrawal-review') dto.props.feePercent = '10';
    const state = { dto, boot };
    prepare(state);
    page.setDefaultTimeout(8000);
    page.on('pageerror', (e) => errors.push(e.message));
    await context.route('**/*', async (route) => {
        const req = route.request(),
            u = new URL(req.url()),
            key = u.pathname.replace(/^\/api\/v1/, '');
        if (u.pathname.startsWith('/api/v1')) {
            if (req.method() !== 'GET') {
                const payload = req.headers()['content-type']?.includes('multipart/form-data')
                    ? { multipart: req.postData() }
                    : req.postDataJSON();
                posts.push({ key, payload });
                return route.fulfill(mutate(key, payload, state) ?? { json: { success: true } });
            }
            if (key === '/bootstrap') return route.fulfill({ json: state.boot });
            if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
            if (key.startsWith('/client')) return route.fulfill({ json: state.dto });
            return route.fulfill({ status: 404, json: {} });
        }
        if (req.method() !== 'GET' || u.origin !== origin) return route.abort();
        return route.continue();
    });
    const target = path.startsWith('/cards')
        ? '/pages/cards/index'
        : '/pages/screen/index?path=' + encodeURIComponent(path);
    await page.goto(origin + '/#' + target);
    await page
        .locator('uni-page-body .user-root, uni-page-body .auth-root')
        .first()
        .waitFor({ timeout: 15000 })
        .catch(() => {});
    try {
        await run({ page, posts, state });
    } catch (e) {
        await page.screenshot({ path: out + '/' + name + '-failed.png', fullPage: true });
        throw e;
    }
    assert.deepEqual(errors, [], name);
    await page.screenshot({ path: out + '/' + name + '.png', fullPage: true });
    await context.close();
    console.log('PASS ' + name);
}
const button = (page, text) =>
    page.locator('uni-button').filter({ hasText: new RegExp('^' + text + '$') });
const field = (page, label) =>
    page
        .locator('.form-field')
        .filter({
            has: page.locator('.form-label').filter({ hasText: new RegExp('^' + label + '$') }),
        })
        .locator('input');

async function select(page, label, index = 0) {
    await page.locator('.select-trigger[aria-label="' + label + '"]').click();
    await page.locator('.uni-picker-action-confirm:visible').waitFor();
    if (index) {
        await page.locator('uni-picker-view-column').hover();
        for (let i = 0; i < index; i++) {
            await page.mouse.wheel(0, 30);
            await page.waitForTimeout(160);
        }
    }
    await page.locator('.uni-picker-action-confirm:visible').click();
}
const flow = '/assets/operate?mode=deposit&asset=USDT&fixture=verified';
const setup =
    (mode, asset) =>
    ({ dto }) => {
        dto.props.mode = mode;
        dto.props.selectedAsset = asset;
        dto.props.result = null;
        const a = dto.props.overview.assets.find((a) => a.asset === asset);
        a.available = '1000';
        a.exchange = asset !== 'USDT';
        a.exchangeUnavailableReason = null;
        a.rails = [
            {
                code: asset === 'BTC' ? 'BTC_BITCOIN' : asset + '_ETHEREUM',
                network: asset === 'BTC' ? 'BITCOIN' : 'ETHEREUM',
                deposit: true,
                withdrawal: true,
                minimum: '0.000001',
                feePercent: '10',
            },
        ];
    };
const failed = () => ({
    status: 503,
    json: { error: { message: 'Unable to load. Please try again.' } },
});
try {
    for (const [asset, amount] of [
        ['USDT', '0.12345678'],
        ['USDC', '0.123456'],
        ['ETH', '0.123456789123456789'],
        ['BTC', '0.12345678'],
    ]) {
        await scenario(
            'transfer-precision-' + asset,
            '/wallet/transfer?fixture=verified',
            async ({ page, posts }) => {
                await select(page, 'Currency', ['USDT', 'USDC', 'ETH', 'BTC'].indexOf(asset));
                await field(page, 'Recipient account ID').fill('202600000099');
                await field(page, 'Transfer amount').fill(amount + '1');
                await button(page, 'Review transfer').click();
                await page
                    .getByText('Enter a positive transfer amount.', { exact: true })
                    .waitFor();
                assert.equal(posts.length, 0);
                await field(page, 'Transfer amount').fill(amount);
                await button(page, 'Review transfer').click();
                await field(page, 'Current password').fill('synthetic-only');
                await page.locator('.confirm-check').click();
                await button(page, 'Confirm transfer').click();
                await page.locator('.form-errors').waitFor();
                assert.equal(posts[0].payload.asset, asset);
                assert.equal(posts[0].payload.amount, amount);
                await page.waitForFunction(() =>
                    [...document.querySelectorAll('input')].every(
                        (i) => i.value !== 'synthetic-only',
                    ),
                );
                await field(page, 'Current password').fill('synthetic-only');
                await page.locator('.confirm-check').click();
                await button(page, 'Confirm transfer').click();
                await page.waitForFunction(() =>
                    [...document.querySelectorAll('input')].every(
                        (i) => i.value !== 'synthetic-only',
                    ),
                );
                assert.equal(posts.length, 2);
                assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
            },
            failed,
            ({ dto }) => {
                dto.props.assets.forEach((a) => {
                    a.available = true;
                    a.amount = '1000';
                });
            },
        );
    }
    await scenario(
        'withdrawal-fee-native-precision',
        '/wallet/withdraw?fixture=verified',
        async ({ page, posts }) => {
            await field(page, 'Withdrawal address').fill('T' + '1'.repeat(33));
            await field(page, 'Amount').fill('1.01');
            await button(page, 'Withdraw').click();
            await page.getByText('0.00101 USDT', { exact: true }).waitFor();
            await page.getByText('1.00899 USDT', { exact: true }).waitFor();
            assert.equal(posts.length, 0);
            await button(page, 'Confirm withdrawal').click();
            await page.locator('.form-errors').waitFor();
            assert.equal(posts[0].payload.expected_fee, '0.001010');
        },
        failed,
        ({ dto }) => {
            dto.props.feePercent = '0.1';
        },
    );
    await scenario(
        'withdrawal-edit-requires-review',
        flow,
        async ({ page, posts }) => {
            await select(page, 'Select network');
            await field(page, 'Amount · ETH').fill('1.123456789123456789');
            await field(page, 'Destination address').fill('0x' + '1'.repeat(40));
            await button(page, 'Review withdrawal').click();
            assert.equal(posts.length, 0);
            await page.locator('.confirm-check').click();
            await field(page, 'Amount · ETH').fill('2.123456789123456789');
            await button(page, 'Review withdrawal').waitFor();
            assert.equal(await page.locator('.confirm-check').count(), 0);
            await button(page, 'Review withdrawal').click();
            assert.notEqual(
                await button(page, 'Confirm withdrawal').getAttribute('disabled'),
                null,
            );
            await page.locator('.confirm-check').click();
            await button(page, 'Confirm withdrawal').click();
            await page.locator('.form-errors').waitFor();
            assert.equal(posts[0].payload.amount, '2.123456789123456789');
            assert.equal(posts[0].payload.expected_fee, '0.212345678912345679');
            assert.equal(posts[0].payload.confirmed, true);
        },
        failed,
        setup('withdrawal', 'ETH'),
    );
    await scenario(
        'exchange-exact-quote',
        flow,
        async ({ page, posts }) => {
            await field(page, 'Amount · ETH').fill('0.123456789123456789');
            await button(page, 'Get quote').click();
            await page.locator('.form-errors').waitFor();
            assert.equal(posts[0].payload.mode, 'exchange');
            assert.equal(posts[0].payload.amount, '0.123456789123456789');
            assert.equal(posts[0].key, '/client/assets/orders');
            await button(page, 'Get quote').click();
            await page.waitForTimeout(200);
            assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
        },
        failed,
        setup('exchange', 'ETH'),
    );
    await scenario(
        'exchange-expiry',
        flow,
        async ({ page, posts }) => {
            const confirm = page.locator('uni-button').filter({ hasText: /^Confirm exchange/ });
            await confirm.waitFor();
            await page.waitForTimeout(3500);
            assert.notEqual(await confirm.getAttribute('disabled'), null);
            assert.equal(posts.length, 0);
        },
        failed,
        (s) => {
            setup('exchange', 'ETH')(s);
            s.dto.props.result = {
                id,
                asset: 'ETH',
                amount: '0.1',
                rate: '2000',
                fee: '0',
                receive: '200',
                state: 'Review exchange',
                expiresAt: new Date(Date.now() + 2000).toISOString(),
                canConfirm: true,
            };
        },
    );
    await scenario(
        'deposit-exact-order',
        flow,
        async ({ page, posts }) => {
            await select(page, 'Select network');
            await field(page, 'Amount · BTC').fill('0.00012345');
            await button(page, 'Create deposit order').click();
            await page.locator('.form-errors').waitFor();
            assert.equal(posts[0].payload.amount, '0.00012345');
            assert.equal(posts[0].payload.rail, 'BTC_BITCOIN');
            assert.equal(posts[0].payload.mode, 'deposit');
            await button(page, 'Create deposit order').click();
            await page.waitForTimeout(200);
            assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
        },
        failed,
        setup('deposit', 'BTC'),
    );
    await scenario(
        'exchange-USDT-unavailable',
        flow,
        async ({ page, posts }) => {
            await page
                .getByText('Exchange is not available for this asset.', { exact: true })
                .waitFor();
            assert.equal(await button(page, 'Get quote').count(), 0);
            assert.equal(posts.length, 0);
        },
        failed,
        setup('exchange', 'USDT'),
    );
} finally {
    await browser.close();
}
