import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const errors = [];
let checks = 0;
async function login(base, prefix, email) {
    const context = await browser.newContext({ locale: 'zh-CN' });
    const page = await context.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => {
        const request = route.request();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && new URL(request.url()).pathname !== `${prefix}/login`) {
            errors.push('Unexpected live mutation blocked');
            return route.abort();
        }
        return route.continue();
    });
    await page.goto(`${base}${prefix}/login`);
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill('123456');
    await page.getByRole('button', { name: /^(登录|Sign in)$/ }).click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    return page;
}
try {
    const company = await login('http://a.localhost:8000', '/admin', 'owner@a.localhost');
    await company.goto('http://a.localhost:8000/admin/onboarding');
    assert.equal(await company.locator('a[href="/admin/domains"]').count(), 0);
    assert.equal((await company.context().request.get('http://a.localhost:8000/admin/domains')).status(), 404);
    const platform = await login('http://admin.localhost:8000', '/platform', 'owner@platform.local');
    await platform.goto('http://admin.localhost:8000/platform/tenants');
    const detail = platform.locator('a[href^="/platform/tenants/"]').filter({ hasNotText: /创建|Create/ }).first();
    await detail.click();
    await platform.locator('a[href$="/domains"]').click();
    await platform.locator('#hostname').waitFor();
    assert.match(platform.url(), /\/platform\/tenants\/[a-f0-9-]+\/domains$/);
    for (const width of [375, 768, 1440]) {
        await platform.setViewportSize({ width, height: 1000 });
        assert.ok(await platform.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}`);
        checks++;
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ companyDomainAccessRemoved: true, platformManagementVisible: true, responsiveChecks: checks, domainWrites: 0 }));
} finally {
    await browser.close();
}
