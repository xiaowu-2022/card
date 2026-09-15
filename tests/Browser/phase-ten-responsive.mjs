import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const output = process.env.PHASE10_SCREENSHOT_DIR ?? '/tmp/phase10-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const results = [];

function fixture(state) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'tests/Browser/prepare_phase_ten_fixtures.php', state], { cwd: process.cwd(), encoding: 'utf8' }).trim());
}

async function login(page, platform = null) {
    const host = platform === true ? 'admin' : 'a';
    const path = platform === true ? 'platform/login' : platform === false ? 'admin/login' : 'login';
    await page.goto(`http://${host}.localhost:8000/${path}`);
    await page.getByLabel(platform === null ? 'Email or phone' : 'Work email').fill(platform === true ? 'owner@platform.local' : platform === false ? 'owner@a.localhost' : 'user@a.localhost');
    await page.getByLabel('Password').fill('123456');
    await Promise.all([
        page.waitForURL(platform === true ? '**/platform/tenants' : platform === false ? /\/admin\/(?:demo|onboarding)$/ : '**/dashboard'),
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
    if (layout.scrollWidth > layout.viewportWidth || !layout.bottomNavigationSafe) throw new Error(`${viewport.width}px ${name} layout failed: ${JSON.stringify(layout)}`);
    await page.screenshot({ path: `${output}/${viewport.width}-${name}.png`, fullPage: true });
    results.push({ viewport: viewport.width, page: name, ...layout });
}

for (const viewport of viewports) {
    for (const [state, heading, name] of [
        ['setup', 'Apply for a card', 'card-setup'],
        ['pending', 'Confirming cardholder addition', 'cardholder-pending'],
        ['insufficient', 'Confirm card opening', 'insufficient-balance'],
        ['processing', 'Creating your card', 'issue-processing'],
        ['success', 'Your cards', 'issue-success'],
    ]) {
        fixture(state);
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
        await login(page);
        await page.goto('http://a.localhost:8000/cards');
        if (['insufficient', 'setup', 'pending'].includes(state)) await page.getByRole('button', { name: 'Apply for a card', exact: true }).click();
        await page.getByRole('heading', { name: heading, exact: true }).first().waitFor();
        await inspect(page, viewport, name);
        if (errors.length) throw new Error(`${viewport.width}px ${name} console errors: ${errors.join(' | ')}`);
        await context.close();
    }

    fixture('ready');
    const readyContext = await browser.newContext({ viewport });
    const readyPage = await readyContext.newPage();
    const readyErrors = [];
    readyPage.on('pageerror', (error) => readyErrors.push(error.message));
    readyPage.on('console', (message) => { if (message.type() === 'error') readyErrors.push(message.text()); });
    await login(readyPage);
    await readyPage.goto('http://a.localhost:8000/cards');
    await readyPage.getByRole('button', { name: 'Apply for a card', exact: true }).click();
    await readyPage.getByRole('heading', { name: 'Confirm card opening', exact: true }).waitFor();
    await inspect(readyPage, viewport, 'cardholder-ready');
    await readyPage.getByRole('button', { name: 'Open card', exact: true }).click();
    await readyPage.getByRole('heading', { name: 'Confirm card opening', exact: true }).waitFor();
    await inspect(readyPage, viewport, 'open-card-review');
    if (readyErrors.length) throw new Error(`${viewport.width}px ready console errors: ${readyErrors.join(' | ')}`);
    await readyContext.close();

    fixture('success');
    const adminContext = await browser.newContext({ viewport });
    const adminPage = await adminContext.newPage();
    await login(adminPage, false);
    await adminPage.goto('http://a.localhost:8000/admin/cards');
    await adminPage.getByRole('heading', { name: 'Cards', exact: true }).waitFor();
    await inspect(adminPage, viewport, 'tenant-admin-cards');
    await login(adminPage, true);
    await adminPage.goto('http://admin.localhost:8000/platform/cards');
    await adminPage.getByRole('heading', { name: 'Cards', exact: true }).waitFor();
    await inspect(adminPage, viewport, 'platform-cards');
    await adminContext.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
