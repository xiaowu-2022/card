// Isolated visual acceptance: fixture HTML and local assets only; no financial requests.
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const app = manifest['resources/js/app.tsx'];
let locale = 'zh-CN';
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    if (/^\/(build\/assets|images\/marketing\/growth)\/[a-zA-Z0-9._-]+$/.test(path)) {
        res.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : path.endsWith('.jpg') ? 'image/jpeg' : 'text/javascript');
        res.end(await readFile('public' + path)); return;
    }
    const page = { component: 'user/Dashboard', url: '/dashboard', version: 'fixture', props: {
        errors: {}, flash: {}, account: null, wallet: null, kycStatus: 'APPROVED',
        auth: { user: { id: 'fixture', status: 'ACTIVE' } },
        tenant: { name: 'Spec Pay', branding: { brandName: 'Spec Pay', primaryColor: '#39ad8d' } },
        i18n: { locale, timezone: 'Asia/Kuala_Lumpur', enabledLocales: ['zh-CN','en','ms','es'], surface: 'user' },
        assetOverview: { activation: { qualified: true }, cumulativeCommission: '0', estimate: '0', updatedAt: null,
            assets: ['USDT','USDC','ETH','BTC'].map(asset => ({ asset, available: '0', held: '0', deposit: '0', exchange: false, rails: [], activity: [] })) },
    }};
    res.setHeader('Content-Type', 'text/html');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${[...new Set([manifest['resources/css/app.css'].file, ...(app.css ?? [])])].map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script type="application/json" data-page="app">${JSON.stringify(page)}</script><div id="app"></div><script type="module" src="/build/${app.file}"></script></body></html>`);
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
await mkdir('/tmp/card-campaign-browser', { recursive: true });
try {
    for (const width of [375, 768, 1440]) for (locale of ['zh-CN','en','ms','es']) {
        const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/dashboard`);
        const carousel = page.locator('.asset-campaign'); await carousel.waitFor();
        await carousel.scrollIntoViewIfNeeded();
        assert.equal(await carousel.locator('.asset-campaign-play').count(), 0);
        for (const [i, href] of ['/promotion','/cards','/wealth'].entries()) {
            await carousel.locator('.asset-campaign-dot').nth(i).click();
            const slide = carousel.locator('.asset-campaign-slide:visible');
            assert.equal(await slide.getAttribute('href'), href);
            assert.ok(await slide.locator('img').evaluate(img => img.complete && img.naturalWidth > 0));
            assert.ok(await slide.evaluate(el => el.scrollHeight <= el.clientHeight + 1));
            await carousel.screenshot({ path: `/tmp/card-campaign-browser/${width}-${locale}-${i}.png` });
        }
        await carousel.locator('.asset-campaign-dot').nth(2).press('ArrowRight');
        assert.equal(await carousel.locator('.asset-campaign-slide:visible').getAttribute('href'), '/promotion');
        await carousel.dispatchEvent('touchstart', { touches: [{identifier: 1, clientX: 250, clientY: 100}] });
        await carousel.dispatchEvent('touchend', { changedTouches: [{identifier: 1, clientX: 100, clientY: 100}] });
        assert.equal(await carousel.locator('.asset-campaign-slide:visible').getAttribute('href'), '/cards');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        assert.deepEqual(errors, []);
        await page.close();
    }
    const page = await browser.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}/dashboard`);
    await page.locator('.asset-campaign').waitFor();
    await page.waitForTimeout(6400);
    assert.equal(await page.locator('.asset-campaign-slide:visible').getAttribute('href'), '/cards');
    await page.locator('.asset-campaign-play').click();
    await page.waitForTimeout(6400);
    assert.equal(await page.locator('.asset-campaign-slide:visible').getAttribute('href'), '/cards');
    await page.close();
    console.log('Campaign carousel passed: three banners, four locales, three widths, keyboard, swipe, autoplay and pause.');
} finally { await browser.close(); await new Promise(r => server.close(r)); }
