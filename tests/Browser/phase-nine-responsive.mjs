import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const output = process.env.PHASE9_SCREENSHOT_DIR ?? '/tmp/phase9-browser';
await mkdir(output, { recursive: true });
const browser = await chromium.launch(
    process.env.PLAYWRIGHT_BUNDLED === '1'
        ? {
              headless: true,
              args:
                  process.env.PHASE9_DOCKER_HOST === '1'
                      ? [
                            '--host-resolver-rules=MAP a.localhost host.docker.internal, MAP admin.localhost host.docker.internal, MAP localhost host.docker.internal, MAP 0.0.0.0 host.docker.internal',
                        ]
                      : [],
          }
        : { channel: 'chrome', headless: true },
);
const viewports = [
    { width: 375, height: 900 },
    { width: 768, height: 1024 },
    { width: 1440, height: 1000 },
];
const results = [];

async function loginUser(page) {
    await page.goto('http://a.localhost:8000/login');
    await page.getByLabel('Email or phone').fill('user@a.localhost');
    await page.getByLabel('Password').fill('local-password');
    await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in' }).click()]);
}

async function loginAdmin(page, platform = false) {
    await page.goto(`http://${platform ? 'admin' : 'a'}.localhost:8000/${platform ? 'platform' : 'admin'}/login`);
    await page.getByLabel('Work email').fill(platform ? 'owner@platform.local' : 'owner@a.localhost');
    await page.getByLabel('Password').fill('local-password');
    await Promise.all([
        page.waitForURL(platform ? '**/platform/tenants' : /\/admin\/(?:demo|onboarding)$/),
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
    if (process.env.PHASE9_SKIP_FIXTURES !== '1') {
        execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'tests/Browser/prepare_phase_nine_fixtures.php'], { cwd: process.cwd() });
    }
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });

    await loginUser(page);
    await page.goto('http://a.localhost:8000/cards');
    await page.getByRole('heading', { name: 'Mille Card' }).waitFor();
    await inspect(page, viewport, 'user-cards');

    await loginAdmin(page, false);
    await page.goto('http://a.localhost:8000/admin/card-products');
    await page.getByRole('heading', { name: 'Card products' }).waitFor();
    await inspect(page, viewport, 'tenant-card-products');

    await loginAdmin(page, true);
    await page.goto('http://admin.localhost:8000/platform/card-products');
    await page.getByRole('heading', { name: 'Card products' }).waitFor();
    await inspect(page, viewport, 'platform-card-products');

    if (errors.length) throw new Error(`${viewport.width}px console errors: ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
console.log(JSON.stringify({ checks: results.length, consoleErrors: 0, results }, null, 2));
