import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')),
);
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5202';
const browser = process.env.CARD_BROWSER === 'webkit'
    ? await webkit.launch({ headless: true })
    : await chromium.launch({ channel: 'chrome', headless: true });
const id = '11111111-1111-4111-8111-111111111111';
const out = 'artifacts/uni-parity/cards-acceptance';
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
    if (process.env.CARD_CASES && !new RegExp(process.env.CARD_CASES).test(name)) return;
    const context = await browser.newContext({ viewport: { width: 375, height: 900 } }),
        page = await context.newPage(),
        posts = [],
        errors = [];
    let dto = structuredClone(fixture.pages[path]);
    assert.ok(dto, path);
    dto.props.i18n.locale = 'en';
    const boot = structuredClone(dto.props.auth?.user ? fixture.authenticated : fixture.guest);
    boot.locale = 'en';

    const state = { dto, boot };
    state.reads = [];
    prepare(state);
    if (state.viewport) await page.setViewportSize(state.viewport);
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
                return route.fulfill(
                    (await mutate(key, payload, state)) ?? { json: { success: true } },
                );
            }
            state.reads.push(key);
            if (state.read && key.endsWith('/transactions'))
                return route.fulfill(state.read(u, state));
            if (key === '/bootstrap') return route.fulfill({ json: state.boot });
            if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
            if (key.startsWith('/client')) return route.fulfill({ json: state.dto });
            return route.fulfill({ status: 404, json: {} });
        }
        if (req.method() !== 'GET' || u.origin !== origin) return route.abort();
        if (state.withoutContainerUnits && u.pathname.endsWith('.css')) {
            const response = await route.fetch();
            const css = (await response.text()).replace(/[^{};]+:[^{};]*\bcq[whib][^{};]*(?:;|(?=\}))/g, '');
            return route.fulfill({ response, body: css });
        }
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
const escapeRegex = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const button = (page, text) =>
    page.locator('uni-button').filter({ hasText: new RegExp('^' + escapeRegex(text) + '$') });
const field = (page, label) =>
    page
        .locator('.form-field')
        .filter({
            has: page
                .locator('.form-label')
                .filter({ hasText: new RegExp('^' + escapeRegex(label) + '$') }),
        })
        .locator('input');

const path = '/cards?fixture=cards';
const op = (state) => ({
    id,
    kind: 'load',
    state,
    amount: '20.00000000',
    debit: '21.00000000',
    arrival: '20.00000000',
    fee: '1.00000000',
    expiresAt: '2099-01-01T00:00:00+08:00',
    createdAt: '2026-09-26T12:00:00+08:00',
});
const hide = (page) =>
    page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
const holderFields = {
    legal_first_name: 'Synthetic',
    legal_last_name: 'Holder',
    date_of_birth: '1992-03-04',
    email: 'holder@example.test',
    mobile: '13800138000',
    mobile_country_code: 'CN',
    nationality_country_code: 'MY',
    residential_address: '9 Test Road',
    residential_city: 'Kuala Lumpur',
    residential_state: 'Kuala Lumpur',
    residential_country_code: 'MY',
    residential_postal_code: '50000',
    document_type: 'id_card',
    cardholder_name_abbreviation: '',
};
const recipientFields = {
    recipientFirstName: 'Synthetic',
    recipientLastName: 'Recipient',
    mobilePrefix: '1',
    mobile: '2025550123',
    country: 'US',
    state: 'New York',
    city: 'New York',
    addressLine1: '10 Test Road',
    postalCode: '10001',
};
function readyApplication({ dto }) {
    const p = dto.props.products[0];
    p.readyForSetup = true;
    dto.props.cardholder = {
        ...dto.props.cardholder,
        state: 'ready',
        id,
        productId: p.id,
        requestId: id,
        formFactor: 'virtual_card',
    };
}
function physicalApplication(s) {
    readyApplication(s);
    s.dto.props.products[0].supportedFormFactors = ['physical_card'];
    s.dto.props.cardholder.formFactor = 'physical_card';
}
async function openApplication(page) {
    await button(page, 'Apply for a card').click();
    await button(page, 'Open this card').click();
}
async function fillRecipient(page) {
    for (const [label, key] of [
        ['Recipient first name', 'recipientFirstName'],
        ['Recipient last name', 'recipientLastName'],
        ['Phone country code', 'mobilePrefix'],
        ['Phone number', 'mobile'],
        ['Country code', 'country'],
        ['State or province', 'state'],
        ['City', 'city'],
        ['Address line 1', 'addressLine1'],
        ['Postal code', 'postalCode'],
    ])
        await field(page, label).fill(recipientFields[key]);
}
const part = (body, key) => body.split('name="' + key + '"\r\n\r\n')[1]?.split('\r\n')[0];
async function selectOption(page, label, value) {
    await page
        .locator('.select-field')
        .filter({ has: page.locator('.select-label').getByText(label, { exact: true }) })
        .locator('.select-trigger')
        .click();
    await page.locator('.select-panel .search-field input').fill(value);
    await page
        .locator('.select-panel .select-option')
        .filter({ hasText: new RegExp('^' + escapeRegex(value) + '$') })
        .click();
}
async function chooseImage(page, label, mimeType = 'image/png') {
    const buffer =
        mimeType === 'image/png'
            ? Buffer.from(
                  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                  'base64',
              )
            : Buffer.from(
                  await page.evaluate((type) => {
                      const canvas = document.createElement('canvas');
                      canvas.width = canvas.height = 2;
                      return canvas.toDataURL(type).split(',')[1];
                  }, mimeType),
                  'base64',
              );
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
        mimeType,
        buffer,
    });
}
try {
    await scenario('product-display-text', '/cards?fixture=verified', async ({ page, posts }) => {
        await button(page, 'Apply for a card').click();
        const info = page.locator('.product-info:visible');
        await info.getByText('First month free, then USD 2/month', { exact: true }).waitFor();
        assert.match(await info.innerText(), /Online purchases only\.\nNo ATM withdrawals/);
        assert.equal(await info.locator('script').count(), 0);
        await button(page, 'Open this card').click();
        await page.locator('.product-info:visible').getByText('First month free, then USD 2/month', { exact: true }).waitFor();
        assert.match(await page.locator('.total:visible').innerText(), /25(?:\.00)? USDT/);
        assert.equal(await page.locator('.product-info:visible').evaluate(el => getComputedStyle(el.querySelector('.info-text')).whiteSpace), 'pre-wrap');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.ok(posts.every(post => post.key === '/wallet/ensure'));
    }, key => key === '/wallet/ensure' ? { json: { success: true } } : undefined, state => {
        readyApplication(state);
        Object.assign(state.dto.props.products[0], {
            openingFee: '5.00000000', minimumInitialLoad: '20.00000000',
            monthlyFeeText: 'First month free, then USD 2/month',
            notes: 'Online purchases only.\nNo ATM withdrawals.\n<script>alert(1)</script>',
        });
    });
    await scenario(
        'manual-kyc-birthday-card-setup',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            for (const [label, key] of [
                ['Last name', 'legal_last_name'], ['First name', 'legal_first_name'],
                ['Email', 'email'], ['Phone number', 'mobile'],
            ]) await field(page, label).fill(holderFields[key]);
            await page.locator('.birth uni-picker').click();
            await page.locator('.uni-picker-action-confirm:visible').click();
            const birthDate = await page.locator('.birth .date').innerText();
            assert.equal(birthDate, '1990-01-01');
            await button(page, 'Submit cardholder materials').click();
            await page.getByText('Enter a valid birth date before today.', { exact: true }).first().waitFor();
            assert.equal(await page.locator('.birth .date').innerText(), birthDate);
            await button(page, 'Submit cardholder materials').click();
            await button(page, 'Open card').waitFor();
            const submissions = posts.filter((p) => p.key === '/client/cards/cardholder');
            assert.equal(submissions.length, 2);
            assert.equal(submissions[0].payload.date_of_birth, birthDate);
            assert.equal(submissions[1].payload.request_id, submissions[0].payload.request_id);
            assert.equal(submissions[1].payload.date_of_birth, birthDate);
            assert.equal(posts.some((p) => p.key.includes('/issue')), false);
        },
        (key, payload, state) => {
            if (key === '/wallet/ensure') return { json: { success: true } };
            assert.equal(key, '/client/cards/cardholder');
            state.attempts = (state.attempts ?? 0) + 1;
            if (state.attempts === 1) return { status: 422, json: { errors: { date_of_birth: ['Enter a valid birth date before today.'] } } };
            readyApplication(state);
            return { json: { success: true } };
        },
        (state) => {
            state.dto.props.cardholderBirthDateRequired = true;
            state.dto.props.products[0].readyForSetup = true;
        },
    );
    for (const width of [320, 375, 430]) {
        for (const withoutContainerUnits of [false, true]) {
            await scenario(
                `preview-layout-${width}-${withoutContainerUnits ? 'legacy' : 'modern'}`,
                '/cards?fixture=verified',
                async ({ page, posts }) => {
                    await openApplication(page);
                    const card = page.locator('.modal-panel .user-card-product-preview');
                    await card.waitFor();
                    await card.scrollIntoViewIfNeeded();
                    await page.waitForFunction(() => {
                        const images = [...document.querySelectorAll('.modal-panel .user-card-product-preview img')];
                        return images.length === 3 && images.every(image => image.complete && image.naturalWidth > 0);
                    });
                    const bounds = await card.boundingBox();
                    assert.ok(bounds.width <= 320 && bounds.height < 250, JSON.stringify(bounds));
                    for (const [selector, maxWidth] of [['.user-card-chip', 42], ['.user-card-preview-network', 64]]) {
                        const icon = await card.locator(selector).boundingBox();
                        assert.ok(icon.width > 0 && icon.width <= maxWidth + 1, JSON.stringify(icon));
                        assert.ok(icon.height > 0 && icon.height < 60, JSON.stringify(icon));
                        assert.ok(icon.x >= bounds.x && icon.x + icon.width <= bounds.x + bounds.width + 1);
                        assert.ok(icon.y >= bounds.y && icon.y + icon.height <= bounds.y + bounds.height + 1);
                    }
                    const fees = await page.locator('.modal-panel .fee-details').boundingBox();
                    assert.ok(fees.y >= bounds.y + bounds.height);
                    assert.equal(await page.locator('.modal-scroll').evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
                    await field(page, 'Initial card balance').fill('20');
                    await button(page, 'Open card').scrollIntoViewIfNeeded();
                    assert.equal(await button(page, 'Open card').isEnabled(), true);
                    assert.equal(posts.some(post => post.key === '/client/cards/issues'), false);
                },
                () => ({ json: {} }),
                state => {
                    readyApplication(state);
                    state.viewport = { width, height: 740 };
                    state.withoutContainerUnits = withoutContainerUnits;
                },
            );
        }
    }
    await scenario(
        'reveal-expiry',
        path,
        async ({ page, posts }) => {
            await page.clock.install();
            await button(page, 'View').click();
            await field(page, 'Current password').fill('synthetic-only');
            await button(page, 'Confirm').click();
            await page.getByText('4111 1111 1111 1111', { exact: true }).waitFor();
            assert.equal(await field(page, 'Current password').count(), 0);
            await page.clock.runFor(30100);
            assert.equal(await page.locator('.pan').count(), 0);
            assert.equal(posts.length, 1);
            assert.equal(
                await page.evaluate(() =>
                    JSON.stringify(localStorage).includes('4111111111111111'),
                ),
                false,
            );
        },
        () => ({ json: { pan: '4111111111111111', cvv: '123' } }),
    );
    await scenario(
        'reveal-late-response-hidden',
        path,
        async ({ page, posts }) => {
            await button(page, 'View').click();
            await field(page, 'Current password').fill('synthetic-only');
            await button(page, 'Confirm').click();
            await page.waitForTimeout(100);
            await hide(page);
            await page.waitForTimeout(600);
            assert.equal(await page.locator('.pan').count(), 0);
            assert.equal(await page.locator('.modal-panel').count(), 0);
            assert.equal(posts.length, 1);
        },
        async () => {
            await new Promise((r) => setTimeout(r, 500));
            return { json: { pan: '4111111111111111', cvv: '123' } };
        },
    );
    await scenario(
        'load-unknown-query-only',
        path,
        async ({ page, posts }) => {
            await button(page, 'Reload').click();
            await field(page, 'Card operation amount').fill('19');
            const send = page
                .locator('.modal-panel')
                .locator('uni-button')
                .filter({ hasText: /^Reload$/ });
            assert.notEqual(await send.getAttribute('disabled'), null);
            await field(page, 'Card operation amount').fill('20.01');
            await page.waitForFunction(
                () =>
                    !document
                        .querySelector('.modal-panel uni-button.primary')
                        ?.hasAttribute('disabled'),
            );
            await send.click();
            await button(page, 'Check result').waitFor();
            assert.deepEqual(
                posts.map((p) => p.payload.action),
                ['quote', 'confirm'],
            );
            assert.equal(posts[0].payload.amount, '20.01');
            await button(page, 'Check result').click();
            await page.getByText('Completed', { exact: true }).waitFor();
            assert.deepEqual(
                posts.map((p) => p.payload.action),
                ['quote', 'confirm', 'sync'],
            );
            assert.equal(posts[1].payload.order_id, posts[2].payload.order_id);
        },
        (key, p) => ({
            json: op(
                p.action === 'quote'
                    ? 'quoted'
                    : p.action === 'confirm'
                      ? 'confirming'
                      : 'completed',
            ),
        }),
    );
    await scenario(
        'return-confirmation',
        path,
        async ({ page, posts }) => {
            await button(page, 'Return').click();
            await field(page, 'Card operation amount').fill('10.25');
            await field(page, 'Current password').fill('synthetic-only');
            assert.notEqual(await button(page, 'Confirm').getAttribute('disabled'), null);
            await page.locator('.confirm-check').click();
            await button(page, 'Confirm').click();
            await page.getByText('Completed', { exact: true }).waitFor();
            assert.equal(posts.length, 1);
            assert.equal(posts[0].payload.confirmed, true);
            assert.equal(posts[0].payload.amount, '10.25');
            assert.equal(posts[0].payload.action, 'return');
            assert.equal(
                await page.evaluate(() => JSON.stringify(localStorage).includes('synthetic-only')),
                false,
            );
        },
        () => ({ json: { ...op('completed'), kind: 'return' } }),
    );
    await scenario(
        'physical-unknown-clears-pin',
        path,
        async ({ page, posts }) => {
            await button(page, 'Activate physical card').click();
            await field(page, 'Card expiry (MM/YY)').fill('05/29');
            await field(page, 'Card PIN').fill('246810');
            await field(page, 'Confirm card PIN').fill('246810');
            await field(page, 'Current password').fill('synthetic-only');
            await page.locator('.confirm-check').click();
            await button(page, 'Confirm activation').click();
            await page
                .getByText('Activation is awaiting confirmation. Refresh card status.', {
                    exact: true,
                })
                .waitFor();
            assert.notEqual(
                await button(page, 'Activate physical card').getAttribute('disabled'),
                null,
            );
            assert.equal(await field(page, 'Card PIN').count(), 0);
            assert.equal(
                await page.evaluate(() => JSON.stringify(localStorage).includes('246810')),
                false,
            );
            await button(page, 'Refresh card status').click();
            await page.getByText('Physical card activated.', { exact: true }).waitFor();
            assert.equal(posts.filter((p) => p.key.endsWith('/activate')).length, 1);
            assert.equal(posts.filter((p) => p.key.endsWith('/activation/sync')).length, 1);
        },
        (key) => ({ json: { status: key.endsWith('/activate') ? 'UNKNOWN' : 'SUCCEEDED' } }),
    );
    await scenario(
        'transactions-retry-page-read-only',
        path,
        async ({ page, posts, state }) => {
            await button(page, 'Retry').click();
            await page.getByText('Offline merchant', { exact: true }).waitFor();
            await button(page, 'Load more transactions').click();
            await page.waitForTimeout(200);
            assert.equal(await page.getByText('Offline merchant', { exact: true }).count(), 1);
            assert.equal(posts.length, 0);
            assert.deepEqual(state.reads.filter((x) => x.endsWith('/transactions')).length, 3);
        },
        undefined,
        (s) => {
            let n = 0;
            s.read = (u) => {
                n++;
                return n === 1
                    ? { status: 503, json: {} }
                    : {
                          json: {
                              page: Number(u.searchParams.get('page')),
                              hasMore: n === 2,
                              items: [
                                  {
                                      id: 'a'.repeat(64),
                                      cardId: id,
                                      last4: '1234',
                                      amount: '-1.23000000',
                                      currency: 'USD',
                                      type: 'purchase',
                                      state: 'completed',
                                      displayAt: '2026-09-26T12:00:00+08:00',
                                      timeKind: 'recorded',
                                      merchant: 'Offline merchant',
                                  },
                              ],
                          },
                      };
            };
        },
    );
    await scenario(
        'refund-lock',
        path,
        async ({ page, posts }) => {
            await page
                .getByText(
                    'Cards are locked for the security deposit refund. Only transaction history is available.',
                    { exact: true },
                )
                .waitFor();
            assert.equal(await button(page, 'Reload').count(), 0);
            assert.equal(await button(page, 'View').count(), 0);
            assert.equal(posts.length, 0);
        },
        undefined,
        ({ dto }) => {
            dto.props.cards[0].refundLocked = true;
            dto.props.cards[0].management = ['transactions'];
        },
    );
    await scenario(
        'issue-review-retry-intent',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await button(page, 'Apply for a card').click();
            await button(page, 'Open this card').click();
            assert.equal(await field(page, 'Initial card balance').inputValue(), '20');
            await field(page, 'Initial card balance').fill('19');
            await page.waitForTimeout(60);
            assert.notEqual(await button(page, 'Open card').getAttribute('disabled'), null);
            await field(page, 'Initial card balance').fill('20.25');
            await page.waitForTimeout(60);
            await button(page, 'Open card').click();
            assert.equal(posts.length, 0);
            await button(page, 'Confirm and open').click();
            await page
                .getByText(
                    'Unable to complete this request. Check your information and current status.',
                )
                .first()
                .waitFor();
            assert.notEqual(await button(page, 'Cancel').getAttribute('disabled'), null);
            await button(page, 'Confirm and open').click();
            await page.waitForTimeout(150);
            assert.equal(posts.length, 2);
            assert.deepEqual(posts[0], posts[1]);
            assert.equal(posts[0].payload.initial_load_amount, '20.25');
            assert.equal(posts[0].payload.form_factor, 'virtual_card');
        },
        undefined,
        ({ dto }) => {
            const p = dto.props.products[0];
            p.readyForSetup = true;
            p.minimumInitialLoad = '20';
            dto.props.cardholder = {
                ...dto.props.cardholder,
                state: 'ready',
                id,
                productId: p.id,
                requestId: id,
                formFactor: 'virtual_card',
            };
        },
    );
    await scenario(
        'materials-new-application',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            for (const [label, key] of [
                ['Last name', 'legal_last_name'],
                ['First name', 'legal_first_name'],
                ['Email', 'email'],
                ['Phone number', 'mobile'],
                ['Detailed address', 'residential_address'],
                ['Postal code', 'residential_postal_code'],
            ])
                await field(page, label).fill(holderFields[key]);
            await selectOption(page, 'Nationality', 'Malaysia');
            await page.locator('.birth uni-picker').click();
            await page.locator('.uni-picker-content uni-picker-view-column').first().hover();
            await page.mouse.wheel(0, -60);
            await page.waitForTimeout(400);
            await page.locator('.uni-picker-action-confirm:visible').click();
            const birthDate = await page.locator('.birth .date').innerText();
            assert.match(birthDate, /^\d{4}-\d{2}-\d{2}$/);
            await selectOption(page, 'Country / region', 'Malaysia');
            await selectOption(page, 'State / province', 'Kuala Lumpur');
            await selectOption(page, 'City', 'Kuala Lumpur');
            await chooseImage(page, 'Document front / passport photo page');
            await chooseImage(page, 'Document back (optional for passports)', 'image/jpeg');
            await button(page, 'Submit cardholder materials').click();
            await button(page, 'Open card').waitFor();
            assert.equal(posts.length, 1);
            assert.equal(part(posts[0].payload.multipart, 'date_of_birth'), birthDate);
            assert.equal(part(posts[0].payload.multipart, 'residential_country_code'), 'MY');
            assert.equal(part(posts[0].payload.multipart, 'residential_state'), 'Kuala Lumpur');
            assert.equal(part(posts[0].payload.multipart, 'residential_city'), 'Kuala Lumpur');
            await button(page, 'Open card').click();
            assert.equal(posts.length, 1);
            await button(page, 'Confirm and open').click();
            await page.waitForTimeout(150);
            assert.equal(posts.length, 2);
            assert.equal(posts[1].key, '/client/cards/issues');
        },
        (key, p, s) => {
            if (key === '/client/cards/cardholder') readyApplication(s);
            return { json: { success: true } };
        },
        (s) => {
            s.dto.props.products[0].readyForSetup = true;
        },
    );
    await scenario(
        'materials-reject-webp',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            await button(page, 'Edit card application information').click();
            await field(page, 'First name').waitFor();
            await chooseImage(page, 'Document front / passport photo page', 'image/webp');
            await chooseImage(page, 'Document back (optional for passports)');
            await button(page, 'Resubmit details').click();
            await page
                .getByText('Upload a PNG or JPEG document of at most 6 MB.', { exact: true })
                .waitFor();
            assert.equal(posts.filter((p) => p.key === '/client/cards/cardholder').length, 0);
        },
        () => ({ json: { fields: holderFields } }),
        readyApplication,
    );
    await scenario(
        'materials-upload-retry',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            await button(page, 'Edit card application information').click();
            await field(page, 'First name').waitFor();
            await button(page, 'Resubmit details').click();
            await page.getByText('This field is required.').first().waitFor();
            assert.equal(posts.filter((p) => p.key === '/client/cards/cardholder').length, 0);
            for (const label of [
                'Document front / passport photo page',
                'Document back (optional for passports)',
            ])
                await chooseImage(page, label);
            await button(page, 'Resubmit details').click();
            await page
                .getByText(
                    'Unable to complete this request. Check your information and current status.',
                )
                .first()
                .waitFor();
            assert.equal(await field(page, 'First name').isDisabled(), true);
            await button(page, 'Resubmit details').click();
            await button(page, 'Open card').waitFor();
            const writes = posts.filter((p) => p.key === '/client/cards/cardholder');
            assert.equal(writes.length, 2);
            for (const p of writes) {
                assert.equal(part(p.payload.multipart, 'request_id'), id);
                assert.equal(part(p.payload.multipart, 'legal_first_name'), 'Synthetic');
                assert.ok(p.payload.multipart.includes('name="front"'));
                assert.ok(p.payload.multipart.includes('name="back"'));
            }
            assert.equal(await page.locator('.upload-preview').count(), 0);
            assert.equal(
                await page.evaluate(() =>
                    JSON.stringify(localStorage).includes('holder@example.test'),
                ),
                false,
            );
        },
        (key, p, s) => {
            if (key.endsWith('/details')) return { json: { fields: holderFields } };
            if (key === '/client/cards/cardholder') {
                s.uploads = (s.uploads ?? 0) + 1;
                return s.uploads === 1
                    ? {
                          status: 503,
                          json: { error: { message: 'Unable to load. Please try again.' } },
                      }
                    : { json: { success: true } };
            }
        },
        readyApplication,
    );
    await scenario(
        'recipient-unknown-inspect',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            assert.notEqual(await button(page, 'Open card').getAttribute('disabled'), null);
            await fillRecipient(page);
            await button(page, 'Save recipient').click();
            await page
                .getByText('Recipient creation could not be confirmed. Do not submit again.', {
                    exact: true,
                })
                .waitFor();
            assert.equal(await button(page, 'Save recipient').count(), 0);
            await button(page, 'Review recipient and refresh status').click();
            await page
                .getByText('Recipient saved for this application.', { exact: true })
                .waitFor();
            await button(page, 'Open card').click();
            await button(page, 'Confirm and open').click();
            await page.waitForTimeout(150);
            assert.equal(posts.filter((p) => p.key === '/client/cards/recipients').length, 1);
            assert.equal(posts.filter((p) => p.key.endsWith('/inspect')).length, 1);
            const issued = posts.find((p) => p.key === '/client/cards/issues');
            assert.equal(issued.payload.recipient_application_id, id);
            assert.equal(issued.payload.form_factor, 'physical_card');
        },
        (key) => ({
            json: key.endsWith('/inspect')
                ? { id, status: 'READY', fields: recipientFields }
                : key.endsWith('/recipients')
                  ? { id, status: 'UNKNOWN' }
                  : { success: true },
        }),
        physicalApplication,
    );
    await scenario(
        'recipient-failure-correction',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            await fillRecipient(page);
            await button(page, 'Save recipient').click();
            await button(page, 'Correct recipient details').waitFor();
            assert.equal(await field(page, 'City').isDisabled(), true);
            await button(page, 'Correct recipient details').click();
            await field(page, 'City').fill('Albany');
            await button(page, 'Save recipient').click();
            await page
                .getByText('Recipient saved for this application.', { exact: true })
                .waitFor();
            assert.equal(posts.length, 2);
            assert.notEqual(posts[0].payload.request_id, posts[1].payload.request_id);
            assert.equal(posts[1].payload.city, 'Albany');
        },
        (key, p, s) => {
            s.attempt = (s.attempt ?? 0) + 1;
            return { json: { id, status: s.attempt === 1 ? 'FAILED' : 'READY' } };
        },
        physicalApplication,
    );
    await scenario(
        'recipient-transport-retry',
        '/cards?fixture=verified',
        async ({ page, posts }) => {
            await openApplication(page);
            await fillRecipient(page);
            await button(page, 'Save recipient').click();
            await page
                .getByText(
                    'Unable to complete this request. Check your information and current status.',
                )
                .first()
                .waitFor();
            assert.equal(await field(page, 'City').isDisabled(), true);
            await button(page, 'Save recipient').click();
            await page
                .getByText('Recipient saved for this application.', { exact: true })
                .waitFor();
            assert.equal(posts.length, 2);
            assert.deepEqual(posts[0], posts[1]);
        },
        (key, p, s) => {
            s.attempt = (s.attempt ?? 0) + 1;
            return s.attempt === 1
                ? { status: 503, json: { error: { message: 'Unable to load. Please try again.' } } }
                : { json: { id, status: 'READY' } };
        },
        physicalApplication,
    );
    await scenario('issue-unknown-no-submit', '/cards?fixture=unknown', async ({ page, posts }) => {
        await page.getByText('Creating your card', { exact: true }).waitFor();
        assert.equal(await button(page, 'Apply for a card').count(), 0);
        assert.equal(posts.length, 0);
    });
    if (process.env.CARD_LAYOUTS) {
        for (const language of ['zh-CN', 'en', 'ms', 'es'])
            for (const width of [375, 768, 1440])
                for (const kind of ['materials', 'recipient', 'load']) {
                    await scenario(
                        `layout-${kind}-${language}-${width}`,
                        kind === 'load' ? path : '/cards?fixture=verified',
                        async ({ page }) => {
                            if (kind === 'load') {
                                await page.locator('.actions uni-button').nth(1).click();
                                await page.locator('.modal-panel .form-input').waitFor();
                            } else {
                                await page.locator('.apply').click();
                                await page.locator('.product .primary').click();
                                await page
                                    .locator(kind === 'materials' ? '.holder-fields' : '.recipient')
                                    .waitFor();
                            }
                            const layout = await page.evaluate(() => {
                                const p = document.querySelector('.modal-panel'),
                                    b = p.getBoundingClientRect();
                                return {
                                    pageOverflow: document.documentElement.scrollWidth > innerWidth,
                                    left: b.left,
                                    right: b.right,
                                    viewport: innerWidth,
                                    formOverflow: p.scrollWidth > p.clientWidth + 1,
                                };
                            });
                            assert.equal(layout.pageOverflow, false);
                            assert.equal(layout.formOverflow, false);
                            assert.ok(layout.left >= 0 && layout.right <= layout.viewport);
                        },
                        undefined,
                        (s) => {
                            if (kind === 'recipient') physicalApplication(s);
                            else if (kind === 'materials')
                                s.dto.props.products[0].readyForSetup = true;
                            s.dto.props.i18n.locale = language;
                            s.boot.locale = language;
                            s.viewport = { width, height: 900 };
                        },
                    );
                }
    }
} finally {
    await browser.close();
}
