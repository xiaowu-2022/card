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
const out = 'artifacts/uni-parity/flows';
mkdirSync(out, { recursive: true });
async function scenario(
    name,
    path,
    run,
    mutate = () => ({
        status: 503,
        json: { error: { message: 'Unable to load. Please try again.' } },
    }),
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
try {
    await scenario(
        'transfer-retry',
        '/wallet/transfer?fixture=verified',
        async ({ page, posts }) => {
            await field(page, 'Recipient account ID').fill('202600000099');
            await field(page, 'Transfer amount').fill('0.12345678');
            await button(page, 'Review transfer').click();
            assert.equal(posts.length, 0);
            const confirm = button(page, 'Confirm transfer');
            assert.equal((await confirm.getAttribute('disabled')) !== null, true);
            await field(page, 'Current password').fill('synthetic-only');
            await page.locator('.confirm-check').click();
            await confirm.click();
            await page
                .getByText(
                    'If the result is unclear, retry this same transfer. Do not start a new request.',
                )
                .waitFor();
            await page.waitForFunction(() =>
                [...document.querySelectorAll('input')].every((i) => i.value !== 'synthetic-only'),
            );
            assert.equal(posts.length, 1);
            assert.equal(posts[0].payload.amount, '0.12345678');
            assert.equal(posts[0].payload.asset, 'USDT');
            assert.equal(await field(page, 'Current password').inputValue(), '');
            assert.equal((await button(page, 'Edit').getAttribute('disabled')) !== null, true);
            await field(page, 'Current password').fill('synthetic-only');
            await page.locator('.confirm-check').click();
            await confirm.click();
            await page.waitForTimeout(150);
            assert.equal(posts.length, 2);
            assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
            assert.equal(
                await page.evaluate(() => JSON.stringify(localStorage).includes('synthetic-only')),
                false,
            );
        },
    );
    await scenario(
        'withdrawal-review',
        '/wallet/withdraw?fixture=verified',
        async ({ page, posts, state }) => {
            await field(page, 'Withdrawal address').fill('T' + '1'.repeat(33));
            await field(page, 'Amount').fill('100');
            await button(page, 'Withdraw').click();
            assert.equal(posts.length, 0);
            await button(page, 'Confirm withdrawal').click();
            await page.waitForTimeout(200);
            assert.equal(posts.length, 1);
            assert.equal(posts[0].payload.amount, '100');
        },
    );
    await scenario(
        'card-details-private',
        '/cards?fixture=cards',
        async ({ page, posts }) => {
            await button(page, 'View').click();
            await field(page, 'Current password').fill('synthetic-only');
            await button(page, 'Confirm').click();
            await page.getByText('4111 1111 1111 1111', { exact: true }).waitFor();
            assert.equal(await field(page, 'Current password').count(), 0);
            await page.locator('.modal-panel').getByLabel('Close').click();
            assert.equal(await page.getByText('4111 1111 1111 1111', { exact: true }).count(), 0);
            assert.equal(posts.filter((p) => p.payload.action === 'reveal').length, 1);
            assert.equal(posts.filter((p) => p.payload.action === 'refresh').length, 0);
        },
        (key, payload) =>
            payload.action === 'reveal'
                ? { json: { pan: '4111111111111111', cvv: '123' } }
                : { json: { success: true } },
    );
    await scenario(
        'wealth-confirmation',
        '/wealth/assets/USDT?fixture=enabled',
        async ({ page, posts }) => {
            await field(page, 'Deposit principal').fill('1000.12345678');
            await button(page, 'Review wealth deposit').click();
            assert.equal(posts.length, 0);
            const confirm = button(page, 'Confirm wealth deposit');
            assert.equal((await confirm.getAttribute('disabled')) !== null, true);
            await page.locator('.confirm-check').click();
            await confirm.click();
            await page.waitForTimeout(200);
            assert.equal(posts.length, 1);
            assert.equal(posts[0].payload.amount, '1000.12345678');
            assert.equal(posts[0].payload.asset, 'USDT');
            assert.equal(posts[0].payload.confirmed, true);
        },
    );
    await scenario(
        'kyc-upload-validation',
        '/kyc',
        async ({ page, posts }) => {
            await field(page, 'Identity number').fill('11010519491231002X');
            for (const label of ['ID front', 'ID back']) {
                const chooser = page.waitForEvent('filechooser');
                await page
                    .locator('.upload-field')
                    .filter({ has: page.getByText(label, { exact: true }) })
                    .locator('uni-button')
                    .click();
                await (
                    await chooser
                ).setFiles({
                    name: 'synthetic.png',
                    mimeType: 'image/png',
                    buffer: Buffer.from(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                        'base64',
                    ),
                });
            }
            await button(page, 'Submit for review').click();
            await page
                .getByText(
                    'The document number could not be recognized or does not match. Please upload a clear document image.',
                    { exact: true },
                )
                .waitFor();
            assert.equal(posts.length, 1);
            assert.ok(posts[0].payload.multipart.includes('name="front"'));
            assert.ok(posts[0].payload.multipart.includes('name="back"'));
        },
        () => ({
            status: 422,
            json: {
                errors: {
                    identity_number: [
                        'The document number could not be recognized or does not match. Please upload a clear document image.',
                    ],
                },
            },
        }),
    );
    await scenario('card-unknown', '/cards?fixture=unknown', async ({ page, posts }) => {
        await page.getByText('Creating your card', { exact: true }).waitFor();
        assert.equal(posts.length, 0);
        assert.equal(await button(page, 'Apply for a card').count(), 0);
    });
    await scenario(
        'registration-finish',
        '/register/challenges/' + id + '?fixture=VERIFIED',
        async ({ page, posts, state }) => {
            await field(page, 'Display name').fill('Offline acceptance');
            await field(page, 'Password').fill('synthetic-only');
            await field(page, 'Confirm password').fill('synthetic-only');
            await button(page, 'Create account').click();
            await page.waitForTimeout(300);
            assert.equal(posts.length, 1);
            assert.equal(posts[0].payload.display_name, 'Offline acceptance');
            await page.waitForFunction(() => location.hash.includes('/pages/assets/index'));
            await page.getByText('Accounts', { exact: true }).waitFor();
        },
        (key, payload, state) => {
            state.boot = { ...fixture.authenticated, locale: 'en' };
            state.dto = structuredClone(fixture.pages['/dashboard?fixture=verified']);
            state.dto.props.i18n.locale = 'en';
            return { json: { redirect: '/dashboard', csrfToken: 'offline' } };
        },
    );
    // A failed read remains retryable, and old H5 links keep invitation parameters.
    {
        const context = await browser.newContext({ viewport: { width: 375, height: 900 } });
        const page = await context.newPage();
        const boot = structuredClone(fixture.guest);
        boot.locale = 'en';
        let reads = 0;
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('**/*', async (route) => {
            const req = route.request(),
                url = new URL(req.url());
            if (url.pathname.startsWith('/api/v1')) {
                assert.equal(req.method(), 'GET');
                if (url.pathname === '/api/v1/bootstrap') return route.fulfill({ json: boot });
                if (url.pathname === '/api/v1/client/register') {
                    assert.equal(url.searchParams.get('invite'), 'LINKTEST');
                    reads++;
                    if (reads === 1)
                        return route.fulfill({
                            status: 503,
                            json: { error: { message: 'Unable to load. Please try again.' } },
                        });
                    const dto = structuredClone(fixture.pages['/register']);
                    dto.props.i18n.locale = 'en';
                    return route.fulfill({ json: dto });
                }
                return route.fulfill({ status: 404, json: {} });
            }
            if (req.method() !== 'GET' || url.origin !== origin) return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/register?invite=LINKTEST');
        await button(page, 'Try again').waitFor();
        await button(page, 'Try again').click();
        await page.locator('.auth-root').waitFor();
        assert.equal(reads, 2);
        assert.deepEqual(errors, []);
        assert.ok(decodeURIComponent(page.url()).includes('/register?invite=LINKTEST'));
        await page.screenshot({ path: out + '/read-retry-legacy-link.png', fullPage: true });
        await context.close();
        console.log('PASS read-retry-legacy-link');
    }
} finally {
    await browser.close();
}
