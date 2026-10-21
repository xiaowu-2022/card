import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { resolve, extname } from 'node:path';
import { chromium } from 'playwright';
import ts from 'typescript';
const source = readFileSync('mobile/uni-app/src/lib/network-recovery.ts', 'utf8')
    .replace(/^import .*;$/gm, "const t = value => value;")
    .replace('export const networkRecovery', 'window.recovery');
const script = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
let documents = 0, apiRequests = 0;
const server = createServer((req, res) => {
    if (req.url.startsWith('/api/mobile/v1/')) {
        apiRequests++; res.writeHead(503, {'Content-Type':'application/json'});
        res.end(JSON.stringify({error:{code:'SERVICE_UNAVAILABLE'}})); return;
    }
    if (req.url.startsWith('/built/')) {
        const root = resolve('public/h5');
        const file = resolve(root, decodeURIComponent(req.url.split('?')[0].slice('/built/'.length)) || 'index.html');
        if (!file.startsWith(root + '/') || !existsSync(file) || !statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
        res.writeHead(200, {'Content-Type':({'.js':'application/javascript','.css':'text/css','.html':'text/html','.svg':'image/svg+xml','.json':'application/json'})[extname(file)] ?? 'application/octet-stream'});
        res.end(readFileSync(file)); return;
    }
    if (req.url === '/favicon.ico') { res.writeHead(204); res.end(); return; }
    documents++;
    res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' });
    res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery test</title><main>Retained page</main><script>${req.url.includes('disabled') ? "Object.defineProperty(window,'sessionStorage',{get(){throw Error('unavailable')}});" : ''}${script}\nrecovery.failure(); recovery.failure(true);</script>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
    const url = `http://127.0.0.1:${server.address().port}/#/current-page`;
    await page.goto(url);
    assert.equal(await page.getByRole('alertdialog').count(), 1);
    assert.match(await page.getByRole('alertdialog').innerText(), /operation result is unknown/);
    const before = documents;
    await Promise.all([page.waitForEvent('load'), page.getByRole('button', { name: 'Refresh page', exact: true }).click()]);
    assert.equal(new URL(page.url()).hash, '#/current-page');
    assert.equal(await page.getByRole('alertdialog').count(), 0);
    assert.equal(await page.getByRole('status').count(), 1);
    await page.waitForTimeout(1200);
    assert.equal(documents, before + 1, 'persistent outage never reloads automatically');
    await page.getByRole('button', { name: 'Keep current page' }).click();
    await page.evaluate(() => recovery.failure());
    assert.equal(await page.getByRole('status').count(), 0, 'dismissed failure does not reappear');
    await page.evaluate(() => recovery.success(recovery.checkpoint()));
    await page.waitForTimeout(1100);
    assert.equal(await page.evaluate(() => sessionStorage.getItem('consumer-network-recovery-v1')), null);
    await page.evaluate(() => recovery.failure());
    assert.equal(await page.getByRole('alertdialog').count(), 1, 'successful recovery starts a new episode');
    await page.goto(url.replace('/#', '/disabled#'));
    assert.equal(await page.getByRole('alertdialog').count(), 0);
    assert.equal(await page.getByRole('button', { name: /Refresh page|Retry refresh/ }).count(), 0);
    assert.match(await page.getByRole('status').innerText(), /storage is unavailable/);
    const actual = await browser.newPage({ viewport: { width: 375, height: 812 } });
    await actual.goto(`http://127.0.0.1:${server.address().port}/built/#/pages/login/index`);
    await actual.getByRole('button', {name:'Refresh page', exact:true}).waitFor();
    assert.equal(apiRequests, 1, 'compiled H5 startup issues one bootstrap request');
    const currentRoute = actual.url();
    await Promise.all([actual.waitForEvent('load'), actual.getByRole('button', {name:'Refresh page',exact:true}).click()]);
    await actual.getByRole('button', {name:'Retry refresh', exact:true}).waitFor();
    await actual.waitForTimeout(1200);
    assert.equal(actual.url(), currentRoute);
    assert.equal(apiRequests, 2, 'compiled H5 reload retries bootstrap once and stays stopped');
    assert.equal(await actual.getByRole('alertdialog').count(), 0);
    console.log('PASS: browser reload, persistent-outage banner, route retention, dismissal, recovery and unavailable storage; compiled H5 startup sends one bootstrap per document with no refresh loop');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
