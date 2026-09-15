import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const output = process.env.PHASE6_SCREENSHOT_DIR ?? '/tmp/phase6-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const results = [];

async function login(page, host, email) {
    await page.goto(`http://${host}:8000/login`);
    await page.getByLabel('Email or phone').fill(email);
    await page.getByLabel('Password').fill('123456');
    await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in' }).click()]);
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
    execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'tests/Browser/prepare_phase_six_fixtures.php'], { cwd: process.cwd() });
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });

    await login(page, 'a.localhost', 'user@a.localhost');
    await page.goto('http://a.localhost:8000/wallet');
    await page.getByRole('link', { name: 'Pay security deposit' }).waitFor();
    await inspect(page, viewport, 'wallet-deposit');
    await page.goto('http://a.localhost:8000/dashboard');
    await page.getByText('Complete your security deposit').waitFor();
    await inspect(page, viewport, 'dashboard-next-action');
    await page.goto('http://a.localhost:8000/security-deposit');
    await page.getByRole('heading', { name: 'Review deposit' }).waitFor();
    await inspect(page, viewport, 'confirm');
    await Promise.all([page.waitForURL('**/security-deposit/success'), page.getByRole('button', { name: 'Confirm deposit' }).click()]);
    await page.getByRole('heading', { name: 'Security deposit complete' }).waitFor();
    await inspect(page, viewport, 'success');
    await page.goto('http://a.localhost:8000/wallet');
    await page.getByText('Requirement met').waitFor();
    await inspect(page, viewport, 'satisfied');

    const insufficient = await context.newPage();
    insufficient.on('pageerror', (error) => errors.push(error.message));
    insufficient.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    await login(insufficient, 'b.localhost', 'user@b.localhost');
    await insufficient.goto('http://b.localhost:8000/security-deposit');
    await insufficient.getByRole('link', { name: 'Top up wallet' }).waitFor();
    await inspect(insufficient, viewport, 'insufficient');
    if (errors.length) throw new Error(`${viewport.width}px console errors: ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
