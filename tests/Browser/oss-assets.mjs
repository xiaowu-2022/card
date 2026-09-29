// Read-only compiled H5 acceptance. Reject local static requests to prove OSS delivery.
import http from 'node:http';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
const directory = resolve(process.env.OSS_H5_DIR ?? 'dist/clients/specpay/release/h5');
mkdirSync('output/oss-verification', { recursive: true });
const html = readFileSync(resolve(directory, 'index.oss.html'));
const server = http.createServer((req, res) => {
    if (req.method !== 'GET') { res.writeHead(405); res.end(); return; }
    if (req.url === '/react') {
        const upstream = http.request({ hostname: '127.0.0.1', port: 8000, path: '/', method: 'GET', headers: { host: 'a.localhost', 'accept-language': 'en' } }, (response) => {
            let body = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => { body += chunk; });
            response.on('end', () => {
                const tags = readFileSync('output/oss-verification/vite-tags.html', 'utf8');
                body = body.replace(/<script\b(?=[^>]*\btype="module")[^>]*>[\s\S]*?<\/script>/g, '').replace('</head>', tags + '</head>');
                res.writeHead(response.statusCode ?? 502, { 'content-type': 'text/html' }); res.end(body);
            });
        });
        upstream.on('error', () => { res.writeHead(502); res.end(); });
        upstream.end(); return;
    }
    if (req.url.startsWith('/api/v1/')) {
        const upstream = http.request({ hostname: '127.0.0.1', port: 8000, path: req.url, method: 'GET', headers: { accept: 'application/json', host: 'a.localhost', 'accept-language': 'en' } }, (response) => {
            res.writeHead(response.statusCode ?? 502, response.headers); response.pipe(res);
        });
        upstream.on('error', () => { res.writeHead(502); res.end(); });
        upstream.end(); return;
    }
    if (req.url === '/') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(html); return; }
    res.writeHead(404); res.end();
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
    browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL ?? 'chrome' });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-US' });
    const page = await context.newPage();
    const errors = [], responses = [], localStatic = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => {
        if (request.url().startsWith(origin) && /\/(assets|static|images|data)\//.test(new URL(request.url()).pathname)) localStatic.push(request.url());
    });
    page.on('response', (response) => { if (!response.url().startsWith(origin)) responses.push({ type: response.request().resourceType(), status: response.status() }); });
    await page.goto(origin, { waitUntil: 'networkidle', timeout: 180000 });
    await page.locator('uni-page-body').waitFor({ timeout: 60000 });
    await page.screenshot({ path: resolve('output/oss-verification/landing.png'), fullPage: true });
    await page.goto(origin + '/#/pages/login/index', { waitUntil: 'networkidle', timeout: 180000 });
    await page.locator('input[type=password]').waitFor({ timeout: 60000 });
    await page.screenshot({ path: resolve('output/oss-verification/login.png'), fullPage: true });
    await page.goto(origin + '/react', { waitUntil: 'networkidle', timeout: 180000 });
    await page.locator('.marketing-home').waitFor({ timeout: 60000 });
    await page.screenshot({ path: resolve('output/oss-verification/react-landing.png'), fullPage: true });
    assert.deepEqual(errors, []);
    assert.deepEqual(localStatic, []);
    assert.ok(responses.some((response) => response.type === 'script' && response.status === 200));
    assert.ok(responses.some((response) => response.type === 'stylesheet' && response.status === 200));
    assert.ok(responses.some((response) => response.type === 'image' && response.status === 200));
    assert.equal(responses.filter((response) => response.status >= 400).length, 0);
    const report = { passed: true, remoteResponses: responses.length, scripts: responses.filter((r) => r.type === 'script').length, images: responses.filter((r) => r.type === 'image').length, localStaticRequests: localStatic.length, pageErrors: errors.length };
    mkdirSync('output/oss-verification', { recursive: true });
    writeFileSync('output/oss-verification/browser.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
} finally {
    await browser?.close();
    await new Promise((resolve) => server.close(resolve));
}
