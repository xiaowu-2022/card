import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { chromium } from 'playwright';

const script = ts.transpileModule(readFileSync('mobile/uni-app/src/lib/login-viewport-pulse.ts', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
const original = 'width=device-width,initial-scale=1,viewport-fit=cover';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    for (const scenario of ['pulse', 'focused', 'cancelled', 'browser']) {
        const context = await browser.newContext({ isMobile: true, viewport: { width: 393, height: 775 },
            userAgent: scenario === 'browser' ? 'Mozilla/5.0 (Linux; Android 13) Chrome/130.0 Mobile Safari/537.36'
                : 'Mozilla/5.0 (Linux; Android 13; Test; wv) Version/4.0 Chrome/130.0 Mobile Safari/537.36' });
        const page = await context.newPage();
        await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body:
            `<meta name="viewport" content="${original}"><style>body{margin:0;min-height:100vh;background:white}</style><input aria-label="Email"><p>Login</p>` }));
        await page.goto('http://viewport.test/');
        await page.evaluate(source => {
            window.exports = {}; (0, eval)(source);
            window.changes = []; window.sizes = [];
            new MutationObserver(() => changes.push(document.querySelector('meta').content))
                .observe(document.querySelector('meta'), { attributes: true });
            visualViewport.addEventListener('resize', () => sizes.push({ height: visualViewport.height, scale: visualViewport.scale }));
        }, script);
        if (scenario === 'focused') await page.locator('input').focus();
        await page.evaluate(cancel => { window.stopPulse = exports.pulseLoginViewportOnce(); if (cancel) stopPulse(); }, scenario === 'cancelled');
        await page.waitForTimeout(650);
        const result = await page.evaluate(() => ({ meta: document.querySelector('meta').content, changes, sizes, scale: visualViewport.scale }));
        assert.equal(result.meta, original);
        assert.equal(result.scale, 1);
        if (scenario === 'pulse') {
            assert.equal(result.changes.length, 2);
            assert.ok(result.sizes.some(size => size.scale < 1), JSON.stringify(result));
            await page.evaluate(() => exports.pulseLoginViewportOnce());
            await page.waitForTimeout(500);
            assert.equal(await page.evaluate(() => changes.length), 2, 'Only once per document');
        } else assert.equal(result.changes.length, 0, scenario);
        console.log(scenario, JSON.stringify(result));
        await context.close();
    }
} finally { await browser.close(); }
