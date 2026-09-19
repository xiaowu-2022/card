import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const origin = 'http://a.localhost:8010';
const output = '/tmp/card-financial-browser';
await mkdir(output, { recursive: true });
function snapshot(mode = 'snapshot') {
    return JSON.parse(
        execFileSync(
            'docker',
            [
                'compose',
                'exec',
                '-T',
                '-e',
                'APP_ENV=testing',
                '-e',
                'DB_DATABASE=card_ui_test',
                'app',
                'php',
                'tests/Acceptance/financial-fixture.php',
                mode,
            ],
            { encoding: 'utf8' },
        ),
    );
}
const initial = snapshot();
if (initial.orders.find((o) => o.asset === 'USDT')?.status === 'ACTIVE')
    await writeFile(`${output}/before.json`, JSON.stringify(initial, null, 2));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
const errors = [];
const money = (state) =>
    state.balances.find((r) => r.asset_code === 'USDT' && r.account_type === 'USER_AVAILABLE')
        .balance;
async function login(page, admin = false) {
    await page.goto(admin ? 'http://admin.localhost:8010/platform/login' : origin + '/login');
    await page
        .locator(admin ? 'input[type=email]' : '#identifier')
        .fill(admin ? 'owner@platform.local' : 'user@a.localhost');
    await page.locator('input[type=password]').fill('local-password');
    await page.locator('form button:not([type=button])').click();
    await page.waitForURL(admin ? '**/platform/tenants' : '**/dashboard');
}
async function inspect(page, name) {
    await page.locator('main').waitFor();
    assert.ok(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        name + ' overflow',
    );
    assert.ok(!(await page.locator('main').innerText()).includes('{{'), name + ' interpolation');
    await page.screenshot({ path: `${output}/${name}.png`, fullPage: true });
    results.push(name);
}
try {
    const page = await browser.newPage({ viewport: { width: 375, height: 900 } });
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page);
    const order = initial.orders.find((o) => o.asset === 'USDT' && o.paid === '20.00000000');
    assert.equal(order.paid, '20.00000000');
    if (order.status === 'ACTIVE') {
        assert.equal(money(initial), '4020.00000000');
        await page.goto(origin + '/wealth/orders/' + order.id);
        await page.locator('[data-wealth-withdraw]').click();
        await page.locator('#wealth-password').waitFor();
        assert.ok((await page.locator('[role=dialog]').innerText()).includes('980'));
        await page.locator('#wealth-password').fill('local-password');
        await page.locator('[role=dialog] input[type=checkbox]').check();
        await inspect(page, '375-cancel-980-confirmation');
        await page.locator('[role=dialog] form button:not([type=button])').click();
        await page.locator('[role=dialog]').waitFor({ state: 'hidden' });
        const cancelled = snapshot();
        assert.equal(money(cancelled), '5000.00000000');
        assert.equal(cancelled.orders.find((o) => o.id === order.id).status, 'CANCELLED');
        await writeFile(`${output}/after-cancel.json`, JSON.stringify(cancelled, null, 2));
    }
    await page.goto(origin + '/wealth/assets/USDT?view=deposit');
    await page.locator('#wealth-term').selectOption('3');
    await page.locator('#wealth-amount').fill('100');
    await page.locator('form button[type=submit]').click();
    await page.locator('[role=dialog] input[type=checkbox]').check();
    await page.locator('[role=dialog] form button:not([type=button])').click();
    await page.waitForURL('**/wealth/orders/**');
    const deposited = snapshot();
    assert.equal(money(deposited), '4900.00000000');
    assert.equal(deposited.orders.length, initial.orders.length + 1);
    await inspect(page, '375-new-deposit-confirmed');
    await page.locator('[data-wealth-withdraw]').click();
    await page.locator('#wealth-password').fill('local-password');
    await page.locator('[role=dialog] input[type=checkbox]').check();
    await page.locator('[role=dialog] form button:not([type=button])').click();
    await page.locator('[role=dialog]').waitFor({ state: 'hidden' });
    assert.equal(money(snapshot()), '5000.00000000');
    const beforeReads = snapshot().ledgerEntries;
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const [locale, name] of [
            ['zh-CN', '简体中文'],
            ['en', 'English'],
            ['ms', 'Bahasa Melayu'],
            ['es', 'Español'],
        ]) {
            await page.goto(origin + '/account/settings');
            await page.locator('button.user-settings-row[aria-haspopup="menu"]').first().click();
            await page.getByRole('menuitem', { name, exact: false }).click();
            await page.waitForFunction(
                (expected) => document.documentElement.lang === expected,
                locale,
            );
            for (const [path, label] of [
                ['/dashboard', 'assets'],
                ['/funds', 'funds'],
                ['/wealth', 'wealth'],
                ['/wealth/assets/USDT?view=details', 'deposits'],
                ['/promotion/invitations', 'invitations'],
                ['/cards', 'cards'],
                ['/assets/operate?mode=deposit&asset=USDT', 'topup'],
                ['/assets/operate?mode=withdrawal&asset=USDT', 'withdrawal'],
                ['/wallet/transfer', 'transfer'],
                ['/security-deposit', 'guarantee'],
                ['/promotion/membership', 'membership'],
            ]) {
                await page.goto(origin + path);
                assert.equal(await page.locator('html').getAttribute('lang'), locale);
                await inspect(page, `${width}-${locale}-${label}`);
            }
        }
    }
    assert.equal(snapshot().ledgerEntries, beforeReads);
    await page.close();
    const admin = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    admin.on('pageerror', (error) => errors.push(error.message));
    await login(admin, true);
    await admin.goto(
        `http://admin.localhost:8010/platform/tenants/${initial.tenant}/configuration/wealth`,
    );
    await admin.locator('#minimum-USDT').waitFor();
    await admin.locator('#minimum-USDT').fill('2');
    await Promise.all([
        admin.waitForResponse(
            (r) => r.request().method() === 'POST' && r.url().endsWith('/configuration/wealth'),
        ),
        admin.locator('form button:not([type=button])').click(),
    ]);
    await admin.reload();
    assert.equal(await admin.locator('#minimum-USDT').inputValue(), '2');
    assert.match(snapshot().wealthSettings.find((s) => s.asset === 'USDT').minimum, /^2(?:\.0+)?$/);
    assert.equal(snapshot().ledgerEntries, beforeReads);
    for (const width of [375, 768, 1440]) {
        await admin.setViewportSize({ width, height: 1000 });
        await inspect(admin, `${width}-platform-wealth`);
    }
    await admin.close();
    await writeFile(`${output}/after.json`, JSON.stringify(snapshot('reconcile'), null, 2));
    assert.deepEqual(errors, []);
    await writeFile(`${output}/results.json`, JSON.stringify(results, null, 2));
    console.log(
        'Database-backed browser acceptance passed: deposit/cancel persisted, 980 refund, 132 localized pages, read-only ledger invariance, SaaS settings, reconciliation.',
    );
} catch (error) {
    for (const context of browser.contexts())
        for (const page of context.pages()) {
            await page
                .screenshot({ path: `${output}/failure.png`, fullPage: true })
                .catch(() => {});
            console.error(page.url(), (await page.locator('body').innerText()).slice(0, 1500));
        }
    throw error;
} finally {
    await browser.close();
}
