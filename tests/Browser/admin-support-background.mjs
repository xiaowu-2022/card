// Synthetic browser fixture only: no server messages, provider operations or database.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from 'playwright';
const origin = 'http://admin.localhost';
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.tsx'];
const shared = {
    auth: { admin: { id: 'offline-admin', name: 'Offline Admin', email: 'offline@example.test', scope: 'PLATFORM', permissions: ['support.read', 'support.bot.manage'] }, user: null },
    tenant: null, flash: { success: null }, errors: {}, requestId: 'offline-fixture',
    i18n: { locale: 'en', enabledLocales: ['en', 'zh-CN'], timezone: 'Asia/Kuala_Lumpur', surface: 'platform' },
};
function data(path) {
    const support = path === '/platform/support';
    return { component: support ? 'platform/Support' : 'errors/DomainError', url: path, version: 'offline-background', clearHistory: false, encryptHistory: false,
        props: { ...shared, ...(support ? { inbox: { data: [], total: 0, current_page: 1, last_page: 1, per_page: 30, prev_page_url: null, next_page_url: null }, chat: null, companies: [], filters: {}, supportName: null } : { status: 503, message: 'Offline fixture screen', requestId: 'offline-fixture' }) } };
}
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [], requests = [];
    let count = 0;
    page.on('pageerror', error => errors.push(error.message));
    await context.addInitScript(() => {
        window.offlineSupport = { sounds: 0, primes: 0, players: 0, sockets: [], hidden: false };
        Object.defineProperty(document, 'hidden', { get: () => window.offlineSupport.hidden });
        Object.defineProperty(document, 'visibilityState', { get: () => window.offlineSupport.hidden ? 'hidden' : 'visible' });
        window.Audio = class {
            paused = true; muted = false; currentTime = 0;
            constructor() { window.offlineSupport.players++; }
            play() { if (this.muted) window.offlineSupport.primes++; else window.offlineSupport.sounds++; this.paused = false; return Promise.resolve(); }
            pause() { this.paused = true; }
        };
        window.WebSocket = class {
            closed = false; onmessage; onclose; onerror;
            constructor() {
                window.offlineSupport.sockets.push(this);
                setTimeout(() => this.emit('pusher:connection_established', { socket_id: '1.2' }), 10);
            }
            emit(event, data, channel) { if (!this.closed) this.onmessage?.({ data: JSON.stringify({ event, data: JSON.stringify(data), channel }) }); }
            send(raw) { const frame = JSON.parse(raw); if (frame.event === 'pusher:subscribe') setTimeout(() => this.emit('pusher_internal:subscription_succeeded', {}, frame.data.channel), 0); }
            close() { this.closed = true; this.onclose?.(); }
        };
    });
    await context.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin) return route.abort();
        requests.push({ path: url.pathname, method: request.method() });
        if (url.pathname === '/platform/support/unread') return route.fulfill({ json: { count } });
        if (url.pathname === '/platform/support/realtime') return route.fulfill({ json: { enabled: true, path: '/app/offline-key', channels: ['private-support.platform'] } });
        if (url.pathname === '/platform/support/realtime/auth') return route.fulfill({ json: { auth: 'offline-key:synthetic-signature' } });
        if (url.pathname.startsWith('/build/')) {
            const file = resolve('public', '.' + url.pathname);
            if (!file.startsWith(resolve('public/build') + '/') || !existsSync(file)) return route.abort();
            return route.fulfill({ path: file });
        }
        if (url.pathname.startsWith('/platform/')) {
            const fixture = data(url.pathname);
            if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: fixture });
            const json = JSON.stringify(fixture).replaceAll('<', '\\u003c');
            const html = `<html><head><meta name="csrf-token" content="offline-csrf">${(entry.css ?? []).map(path => `<link rel="stylesheet" href="/build/${path}">`).join('')}</head><body><script data-page="app" type="application/json">${json}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
            return route.fulfill({ contentType: 'text/html', body: html });
        }
        return route.fulfill({ status: 404, body: '' });
    });
    await page.goto(origin + '/platform/support');
    await page.waitForFunction(() => window.offlineSupport.players === 1 && window.offlineSupport.sockets.length === 1, undefined, { timeout: 10000 }).catch(async error => {
        console.log(JSON.stringify({ errors, requests: requests.filter(r => r.path.startsWith('/platform')), state: await page.evaluate(() => ({ players: window.offlineSupport.players, sockets: window.offlineSupport.sockets.length })), text: (await page.locator('body').innerText()).slice(0, 500) }));
        throw error;
    });
    await page.locator('a[href="/platform/support/bot"]').last().click();
    await page.waitForURL('**/platform/support/bot');
    await page.waitForFunction(() => window.offlineSupport.primes === 1);
    assert.equal(await page.evaluate(() => window.offlineSupport.players), 1, 'audio element persists across different Inertia page components');
    assert.equal(await page.evaluate(() => window.offlineSupport.sockets.length), 1, 'connection persists across backend navigation');
    await page.evaluate(() => { window.offlineSupport.hidden = true; document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.evaluate(() => window.offlineSupport.sockets[0].closed), false, 'hidden tab keeps websocket');
    count = 1;
    await page.evaluate(() => window.offlineSupport.sockets[0].emit('support.changed', { refresh: true }, 'private-support.platform'));
    await page.waitForFunction(() => window.offlineSupport.sounds === 1);
    assert.equal(requests.some(request => /\/read$/.test(request.path)), false, 'background reminders never acknowledge a chat');
    assert.equal(await page.evaluate(() => window.offlineSupport.players), 1);
    await page.evaluate(() => { window.offlineSupport.hidden = false; document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.evaluate(() => window.offlineSupport.sockets.length), 1, 'returning to tab keeps connection');
    assert.deepEqual(errors, []);
    console.log('Offline browser check passed: persistent reminder across pages, hidden-tab socket and sound, no background read acknowledgements.');
} finally { await browser.close(); }
