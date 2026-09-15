import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const output = process.env.PHASE8_SCREENSHOT_DIR ?? '/tmp/phase8-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const results = [];

async function login(page, admin = false) {
    await page.goto(`http://a.localhost:8000/${admin ? 'admin/login' : 'login'}`);
    await page.getByLabel(admin ? 'Work email' : 'Email or phone').fill(admin ? 'owner@a.localhost' : 'user@a.localhost');
    await page.getByLabel('Password').fill('123456');
    await Promise.all([
        page.waitForURL(admin ? /\/admin\/(?:demo|onboarding)$/ : '**/dashboard'),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function inspect(page, viewport, name) {
    const layout = await page.evaluate(() => {
        const fixedBottomNav = [...document.querySelectorAll('nav')].find((element) => {
            const style = getComputedStyle(element);
            return style.position === 'fixed' && style.bottom === '0px' && element.getBoundingClientRect().height > 0;
        });
        const main = document.querySelector('main');
        return {
            scrollWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
            bottomNavigationSafe: !fixedBottomNav || !main || parseFloat(getComputedStyle(main).paddingBottom) >= fixedBottomNav.getBoundingClientRect().height,
        };
    });
    if (layout.scrollWidth > layout.viewportWidth || !layout.bottomNavigationSafe) {
        throw new Error(`${viewport.width}px ${name} layout failed: ${JSON.stringify(layout)}`);
    }
    await page.screenshot({ path: `${output}/${viewport.width}-${name}.png`, fullPage: true });
    results.push({ viewport: viewport.width, page: name, ...layout });
}

for (const viewport of viewports) {
    const fixture = JSON.parse(execFileSync(
        'docker', ['compose', 'exec', '-T', 'app', 'php', 'tests/Browser/prepare_phase_eight_fixtures.php'],
        { cwd: process.cwd(), encoding: 'utf8' },
    ).trim());
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });

    await login(page);
    await page.goto('http://a.localhost:8000/wallet/top-up');
    await page.getByRole('heading', { name: 'Top up' }).waitFor();
    await inspect(page, viewport, 'amount-entry');
    await page.getByLabel('Amount').fill('100.00');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByRole('heading', { name: 'Create payment instructions' }).waitFor();
    await inspect(page, viewport, 'amount-review');

    for (const [name, id, heading] of [
        ['waiting', fixture.waitingOrderId, 'Waiting for payment'],
        ['confirming', fixture.confirmingOrderId, 'Payment detected'],
        ['success', fixture.successOrderId, 'Top-up complete'],
        ['expired', fixture.expiredOrderId, 'Top-up expired'],
    ]) {
        await page.goto(`http://a.localhost:8000/wallet/top-ups/${id}/return`);
        await page.getByRole('heading', { name: heading }).waitFor();
        await inspect(page, viewport, name);
    }
    await page.goto('http://a.localhost:8000/wallet/top-up');
    await page.getByText('Top-up history').waitFor();
    await inspect(page, viewport, 'history');
    await page.goto('http://a.localhost:8000/wallet');
    await page.getByRole('heading', { name: 'Wallet' }).waitFor();
    await inspect(page, viewport, 'wallet');

    await login(page, true);
    await page.goto(`http://a.localhost:8000/admin/topups/${fixture.confirmingOrderId}`);
    await page.getByText('Settlement details').waitFor();
    await inspect(page, viewport, 'admin-read-only');

    if (errors.length) throw new Error(`${viewport.width}px console errors: ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
