import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const output = '/tmp/card-marketing-home';
await mkdir(output, { recursive: true });
const errors = [];
let responsiveChecks = 0;
try {
    for (const locale of ['zh-CN', 'en', 'ms', 'es']) {
        const context = await browser.newContext({ locale });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            if (!['GET', 'HEAD', 'OPTIONS'].includes(route.request().method())) {
                errors.push('Unexpected homepage write blocked');
                return route.abort();
            }
            return route.continue();
        });
        // The locale UI is exercised without persisting browser preferences on the site.
        await page.route('**/locale', async route => {
            assert.equal(route.request().method(), 'POST');
            assert.ok(['en', 'zh-CN', 'ms', 'es'].includes(route.request().postDataJSON().locale));
            await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
        });
        const response = await page.goto('http://a.localhost:8000/');
        assert.equal(response.status(), 200);
        await page.locator('.marketing-hero h1').waitFor();
        const content = await page.locator('main').innerText();
        assert.doesNotMatch(content, /PokePay|FINTRAC|M24040069|RDWW|MOCK|12,840|Phase 0|130 M|MSB|VASP|0%/i);
        assert.equal(await page.locator('a[href="/demo"]').count(), 0);
        assert.ok(await page.locator('a[href="/login"]').count() > 0);
        assert.equal(await page.locator('a[href="/admin/login"]').count(), 0);
        await page.locator('.marketing-showcase-tabs button').nth(1).click();
        assert.equal(await page.locator('.marketing-showcase-tabs button').nth(1).getAttribute('aria-pressed'), 'true');
        await page.locator('.marketing-faq summary').first().click();
        assert.equal(await page.locator('.marketing-faq details').first().evaluate(el => el.open), true);
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.evaluate(() => window.scrollTo(0, 0));
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow ${locale} ${width}`);
            await page.evaluate(() => document.fonts.ready);
            const card = await page.locator('.marketing-hero-card').boundingBox();
            const title = await page.locator('.marketing-hero-copy').boundingBox();
            const action = await page.locator('.marketing-hero-action').boundingBox();
            assert.equal(await page.locator('.marketing-hero-card').count(), 1);
            assert.ok(Math.abs(card.width / card.height - 1488 / 870) < 0.01);
            if (width < 1024) {
                assert.ok(title.y + title.height <= card.y);
                assert.ok(card.y + card.height <= action.y);
                assert.ok(card.width <= (width === 375 ? 327 : 520));
            } else {
                assert.ok(title.x + title.width <= card.x);
                assert.ok(card.width <= 620);
            }
            for (const selector of ['.marketing-hero .marketing-cta', '.marketing-scroll', '.marketing-header .marketing-pill', '.marketing-header .user-header-action']) {
                const box = await page.locator(selector).boundingBox();
                assert.ok(box.width >= 44 && box.height >= 44, `${selector}: touch target`);
            }
            assert.equal(await page.locator('.marketing-hero .marketing-cta').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(214, 189, 121)');
            await page.screenshot({ path: `${output}/${locale}-${width}.png`, fullPage: true });
            if (width === 1440) await page.screenshot({ path: `${output}/${locale}-hero.png` });
            if (width === 375) {
                await page.locator('.marketing-menu').click();
                assert.ok(await page.getByRole('dialog').isVisible());
                await page.getByRole('dialog').locator('a[href="#faq"]').click();
                assert.equal(await page.getByRole('dialog').count(), 0);
            }
            responsiveChecks++;
        }
        await page.setViewportSize({ width: 375, height: 900 });
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.locator('.marketing-header .user-header-action').click();
        await page.getByRole('menuitem').filter({ hasText: locale === 'en' ? '简体中文' : 'English' }).click();
        await page.waitForFunction(expected => document.documentElement.lang === expected, locale === 'en' ? 'zh-CN' : 'en');
        assert.equal(await page.locator('.marketing-hero .marketing-cta').getAttribute('href'), '/login');
        assert.equal(await page.locator('.marketing-header .marketing-pill').getAttribute('href'), '/register');
        await context.close();
    }
    const longContext = await browser.newContext({ locale: 'en' });
    const longPage = await longContext.newPage();
    const longBrand = 'International Example Company With A Very Long Brand Name';
    longPage.on('pageerror', error => errors.push(error.message));
    await longPage.route('**/*', async route => {
        if (!['GET', 'HEAD', 'OPTIONS'].includes(route.request().method())) return route.abort();
        // Alter only the test's Inertia response after the real document loads. This
        // preserves Chrome's local-address classification for the Vite script origin.
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.tenant.branding.brandName = longBrand;
        await route.fulfill({ response, json: data });
    });
    await longPage.goto('http://a.localhost:8000/');
    await longPage.locator('.marketing-hero-card').waitFor();
    await longPage.locator('.marketing-header .marketing-brand').click();
    await longPage.locator('.marketing-long-brand').waitFor();
    for (const width of [375, 768, 1440]) {
        await longPage.setViewportSize({ width, height: 1000 });
        assert.ok(await longPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Long brand overflow ${width}`);
        const box = await longPage.locator('.marketing-hero-copy').boundingBox();
        const action = await longPage.locator('.marketing-hero-action').boundingBox();
        assert.ok(box.y + box.height <= action.y);
        await longPage.screenshot({ path: `${output}/long-brand-${width}.png`, fullPage: true });
        responsiveChecks++;
    }
    await longContext.close();
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks, localLoginLinks: true, showcaseAndFaq: true, liveWrites: 0, screenshots: output }));
} finally {
    await browser.close();
}
