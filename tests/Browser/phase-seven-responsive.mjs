import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const output = process.env.PHASE7_SCREENSHOT_DIR ?? '/tmp/phase7-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const results = [];

async function loginUser(page) {
    await page.goto('http://a.localhost:8000/login');
    await page.getByLabel('Email or phone').fill('user@a.localhost');
    await page.getByLabel('Password').fill('123456');
    await Promise.all([
        page.waitForURL('**/dashboard'),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function loginAdmin(page) {
    await page.goto('http://a.localhost:8000/admin/login');
    await page.getByLabel('Work email').fill('owner@a.localhost');
    await page.getByLabel('Password').fill('123456');
    await Promise.all([
        page.waitForURL(/\/admin\/(?:demo|onboarding)$/),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function inspect(page, viewport, name) {
    const layout = await page.evaluate(() => {
        const fixedBottomNav = [...document.querySelectorAll('nav')].find((element) => {
            const style = getComputedStyle(element);
            return (
                style.position === 'fixed' &&
                style.bottom === '0px' &&
                element.getBoundingClientRect().height > 0
            );
        });
        const main = document.querySelector('main');
        return {
            scrollWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
            bottomNavigationSafe:
                !fixedBottomNav ||
                !main ||
                parseFloat(getComputedStyle(main).paddingBottom) >=
                    fixedBottomNav.getBoundingClientRect().height,
        };
    });
    if (layout.scrollWidth > layout.viewportWidth || !layout.bottomNavigationSafe) {
        throw new Error(`${viewport.width}px ${name} layout failed: ${JSON.stringify(layout)}`);
    }
    await page.screenshot({ path: `${output}/${viewport.width}-${name}.png`, fullPage: true });
    results.push({ viewport: viewport.width, page: name, ...layout });
}

for (const viewport of viewports) {
    const fixtureOutput = execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'tests/Browser/prepare_phase_seven_fixtures.php'],
        { cwd: process.cwd(), encoding: 'utf8' },
    );
    const fixture = JSON.parse(fixtureOutput.trim());
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });

    await loginUser(page);
    await page.goto('http://a.localhost:8000/wallet/withdraw');
    await page.getByRole('heading', { name: 'Withdraw' }).waitFor();
    await inspect(page, viewport, 'withdrawal-form');
    await page.getByLabel('Amount').fill('25.00000000');
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.getByText('You are withdrawing').waitFor();
    await inspect(page, viewport, 'withdrawal-review');
    await page.goto(`http://a.localhost:8000/wallet/withdrawals/${fixture.pendingOrderId}`);
    await page.getByRole('heading', { name: 'Withdrawal submitted' }).waitFor();
    await inspect(page, viewport, 'withdrawal-pending');
    await page.goto(`http://a.localhost:8000/wallet/withdrawals/${fixture.verifyingOrderId}`);
    await page.getByRole('heading', { name: 'Transfer submitted' }).waitFor();
    await inspect(page, viewport, 'withdrawal-verifying');

    await loginAdmin(page);
    await page.goto(`http://a.localhost:8000/admin/withdrawals/${fixture.pendingOrderId}`);
    await page.getByRole('heading', { name: /40(?:\.0+)? USDT/ }).waitFor();
    await inspect(page, viewport, 'admin-review');
    await page.getByLabel('Password').fill('123456');
    await page.getByRole('button', { name: 'Confirm identity' }).click();
    await page.getByRole('button', { name: 'Reveal withdrawal address' }).waitFor();
    await page.getByRole('button', { name: 'Reveal withdrawal address' }).click();
    await page.getByText(fixture.fullAddress).first().waitFor();
    await inspect(page, viewport, 'admin-address-reveal');
    await page.getByRole('button', { name: 'Approve withdrawal' }).click();
    await page.getByLabel('TRON transaction hash').waitFor();
    await inspect(page, viewport, 'admin-tx-submit');
    await page.getByLabel('TRON transaction hash').fill('e'.repeat(64));
    await page.getByRole('button', { name: 'Submit and verify Tx Hash' }).click();
    await page.getByText('SUCCEEDED').waitFor();
    await inspect(page, viewport, 'admin-success');
    await page.goto(`http://a.localhost:8000/admin/withdrawals/${fixture.verifyingOrderId}`);
    await page.getByRole('button', { name: 'Verify again' }).waitFor();
    await inspect(page, viewport, 'admin-verifying');
    await page.goto(`http://a.localhost:8000/wallet/withdrawals/${fixture.pendingOrderId}`);
    await page.getByRole('heading', { name: 'Withdrawal complete' }).waitFor();
    await inspect(page, viewport, 'withdrawal-success');

    if (errors.length) throw new Error(`${viewport.width}px console errors: ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
