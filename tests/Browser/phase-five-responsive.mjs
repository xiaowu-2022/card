import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = process.env.PHASE5_BASE_URL ?? 'http://a.localhost:8000';
const ids = {
    processing: process.env.PHASE5_PROCESSING_ID,
    credited: process.env.PHASE5_CREDITED_ID,
    failed: process.env.PHASE5_FAILED_ID,
};
if (Object.values(ids).some((id) => !id)) throw new Error('Phase 5 fixture ids are required.');
const output = process.env.PHASE5_SCREENSHOT_DIR ?? '/tmp/phase51-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const paths = [
    ['amount', '/wallet/top-up'],
    ['checkout', `/__mock/payments/${ids.processing}`],
    ['processing', `/wallet/top-ups/${ids.processing}/return`],
    ['credited', `/wallet/top-ups/${ids.credited}/return`],
    ['failure', `/wallet/top-ups/${ids.failed}/return`],
    ['history', '/wallet/top-up'],
    ['wallet', '/wallet'],
];
const results = [];

for (const viewport of viewports) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });
    await page.goto(`${baseUrl}/login`);
    await page.getByLabel('Email or phone').fill('user@a.localhost');
    await page.getByLabel('Password').fill('123456');
    await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in' }).click()]);

    for (const [name, path] of paths) {
        await page.goto(`${baseUrl}${path}`, { waitUntil: 'domcontentloaded' });
        if (name === 'amount') {
            await page.getByLabel('Amount').fill('12.50');
            await page.getByRole('button', { name: 'Continue', exact: true }).click();
            await page.getByRole('heading', { name: 'Review your top-up' }).waitFor();
        }
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
    if (errors.length) throw new Error(`${viewport.width}px console errors: ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
