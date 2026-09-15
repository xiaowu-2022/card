import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Existing local seeded accounts only. Never resets data, submits business forms, or calls providers.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const output = process.env.ADMIN_I18N_SCREENSHOT_DIR ?? '/tmp/card-admin-i18n-browser';
await mkdir(output, { recursive: true });
const results = [], errors = [];
async function switchLanguage(page, locale) {
    await page.getByRole('button', { name: /^(Language|语言)$/ }).click();
    await page.getByRole('menuitem', { name: locale === 'en' ? 'English' : '简体中文' }).click();
    await page.waitForFunction(value => document.documentElement.lang === value, locale);
}
async function inspect(page, width, name) {
    await page.setViewportSize({ width, height: 1000 });
    await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
    const overflow = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, elements: [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth).map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right })).slice(-25) }));
    assert.ok(overflow.scroll <= overflow.width, `${name} at ${width}px overflows: ${JSON.stringify(overflow)}`);
    results.push(`${name} ${width}px`);
}
try {
    for (const platform of [true, false]) {
        const context = await browser.newContext({ locale: 'en-US' });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        const surface = platform ? 'platform' : 'admin';
        const base = `http://${platform ? 'admin' : 'a'}.localhost:8000`;
        const allowedPost = new Set([`/${surface}/login`, `/${surface}/locale`]);
        await page.route('**/*', async route => {
            const request = route.request();
            if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && !allowedPost.has(new URL(request.url()).pathname)) {
                errors.push(`Unexpected mutation: ${new URL(request.url()).pathname}`);
                return route.abort();
            }
            return route.continue();
        });
        await page.goto(`${base}/${surface}/login`);
        await page.waitForFunction(() => document.documentElement.lang === 'zh-CN');
        const email = platform ? 'owner@platform.local' : 'owner@a.localhost';
        await page.getByLabel('工作邮箱').fill(email);
        await page.getByLabel('密码', { exact: true }).fill(process.env.ADMIN_I18N_TEST_PASSWORD ?? '123456');
        await switchLanguage(page, 'en');
        assert.equal(await page.getByLabel('Work email').inputValue(), email);
        assert.equal(await page.getByLabel('Password', { exact: true }).inputValue(), process.env.ADMIN_I18N_TEST_PASSWORD ?? '123456');
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await page.waitForURL(platform ? '**/platform/tenants' : /\/admin\/(onboarding|demo)$/);
        await page.reload();
        await page.waitForFunction(() => document.documentElement.lang === 'en');
        await switchLanguage(page, 'zh-CN');
        await page.reload();
        await page.waitForFunction(() => document.documentElement.lang === 'zh-CN');

        // Verify a business form remains populated; never submit it.
        await page.goto(`${base}/${surface}/${platform ? 'tenants/create' : 'settings/branding'}`);
        const field = page.getByLabel(platform ? '公司法定或经营名称' : '品牌名称', { exact: true });
        await field.fill('Unsaved language-switch test');
        await switchLanguage(page, 'en');
        assert.equal(await page.getByLabel(platform ? 'Legal or operating name' : 'Brand name', { exact: true }).inputValue(), 'Unsaved language-switch test');
        await switchLanguage(page, 'zh-CN');
        assert.equal(await field.inputValue(), 'Unsaved language-switch test');

        const pages = platform ? ['tenants', 'demo', 'card-products', 'cards'] : ['demo', 'users', 'kyc', 'topups', 'withdrawals', 'cards', 'card-products', 'onboarding', 'team', 'domains', 'settings/branding', 'settings/locales', 'settings/business', 'settings/kyc'];
        // Read detail links from rendered lists; never synthesize or mutate resources.
        for (const list of platform ? ['tenants'] : ['users', 'kyc', 'topups', 'withdrawals']) {
            await page.goto(`${base}/${surface}/${list}`);
            const hrefs = await page.locator('main a[href]').evaluateAll(links => links.map(link => link.getAttribute('href')));
            const match = hrefs.find(href => new RegExp(`^/${surface}/${list}/[0-9a-f-]{36}$`).test(href ?? ''));
            if (match) {
                pages.push(match.slice(surface.length + 2));
                if (list === 'users') pages.push(`${match.slice(surface.length + 2)}/wallet`, `${match.slice(surface.length + 2)}/ledger`);
            }
        }
        for (const locale of ['zh-CN', 'en']) {
            if (locale === 'en') await switchLanguage(page, 'en');
            for (const path of pages) {
                await page.goto(`${base}/${surface}/${path}`);
                await page.waitForFunction(value => document.documentElement.lang === value, locale);
                await page.getByRole('button', { name: locale === 'en' ? 'Language' : '语言', exact: true }).waitFor();
                for (const width of [375, 768, 1440]) await inspect(page, width, `${surface}-${path.replaceAll('/', '-')}-${locale}`);
                console.log(`Passed ${surface}/${path} ${locale}: 375/768/1440px`);
            }
        }
        assert.equal((await context.cookies(base)).filter(cookie => cookie.name.startsWith('user_locale_')).length, 0);
        await context.close();
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ passed: results.length, checks: results, screenshots: output }, null, 2));
} finally { await browser.close(); }
