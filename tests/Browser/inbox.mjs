import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

// Entire application and all responses are served from fixtures. No live login or writes.
const root = new URL('../../', import.meta.url);
const manifest = JSON.parse(await readFile(new URL('public/build/manifest.json', root), 'utf8'));
const app = manifest['resources/js/app.tsx'];
const css = new Set([manifest['resources/css/app.css'].file, ...(app.css ?? [])]);
let locale = 'en', count = 101, supportCount = 0, empty = false, failRead = false;
const business = { id: '00000000-0000-4000-8000-000000000001', kind: 'BUSINESS', template: 'deposit', parameters: { amount: '0.000000000000000001', asset: 'ETH' }, time: '2026-09-26T01:23:00Z', readAt: null, href: '/funds' };
const notice = { ...business, id: '00000000-0000-4000-8000-000000000002', kind: 'PLATFORM', title: '长标题'.repeat(30), body: '<script>window.injected = true</script>\n' + 'Long body '.repeat(70), href: null };
const errors = [], writes = [];
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    const json = value => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(value)); };
    if (path.startsWith('/build/')) {
        const file = path.slice(7);
        if (!/^assets\/[\w.-]+$/.test(file)) return res.writeHead(404).end();
        res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
        return res.end(await readFile(new URL('public/build/' + file, root)));
    }
    if (path.startsWith('/images/')) { res.setHeader('Content-Type', 'image/svg+xml'); return res.end(await readFile(new URL('public' + path, root))); }
    if (path === '/favicon.ico') return res.writeHead(204).end();
    if (path === '/messages/unread-count') return json({ count, supportCount });
    if (req.method === 'POST') {
        writes.push(path);
        if (path === '/support/read') { supportCount = 0; return res.writeHead(204).end(); }
        if (path.endsWith('/preview')) return json({ count: 2, token: 'fixture-token' });
        if (path.startsWith('/messages/')) {
            if (!failRead) { count = 0; business.readAt = notice.readAt = '2026-09-26T01:25:00Z'; }
            res.setHeader('X-Inertia', 'true');
            return json(pageFor(path.endsWith('/read-all') ? '/messages' : path.slice(0, -5), failRead ? { form: 'Failed' } : {}));
        }
        if (path === '/platform/tenants/fixture/notifications') {
            res.setHeader('X-Inertia', 'true');
            return json(pageFor('/platform/notifications'));
        }
        throw new Error('Unexpected write: ' + path);
    }
    const data = pageFor(req.url);
    if (req.headers['x-inertia']) { res.setHeader('X-Inertia', 'true'); return json(data); }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${[...css].map(f => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><script type="application/json" data-page="app">${JSON.stringify(data).replaceAll('<', '\\u003c')}</script><div id="app"></div><script type="module" src="/build/${app.file}"></script></body></html>`);
});
function pageFor(url, validation = {}) {
    const path = new URL(url, 'http://localhost').pathname;
    const admin = path.startsWith('/platform');
    const props = { errors: validation, flash: {}, tenant: { id: 'fixture', branding: { brandName: 'Spec Pay', primaryColor: '#39ad8d', logoUrl: null } }, auth: { user: admin ? null : { id: 'fixture', status: 'ACTIVE', displayName: 'Test', email: 'test@example.test', accountId: '202609260001' }, admin: admin ? { id: 'admin', name: 'Test Admin', permissions: ['notifications.read', 'notifications.send'] } : null }, unreadMessages: count, unreadSupport: supportCount, i18n: { locale: admin ? 'en' : locale, timezone: 'Asia/Kuala_Lumpur', enabledLocales: ['zh-CN', 'en', 'ms', 'es'], surface: admin ? 'platform' : 'user' } };
    let component = 'user/Messages';
    if (path === '/account') { component = 'user/Account'; Object.assign(props, { kycStatus: 'APPROVED', promotionRank: 0, accountQualified: true }); }
    else if (path === '/support') { component = 'user/Support'; props.chat = { id: 'support-fixture', before: 0, olderCursor: null, messages: [{ id: 'reply', sequence: 2, fromSupport: true, text: 'Support reply', createdAt: business.time, imageUrl: null }] }; }
    else if (admin) { component = 'platform/Notifications'; Object.assign(props, { companies: [{ id: 'fixture', name: 'Test company' }], company: 'fixture', batches: { data: [{ id: 'batch', title: notice.title, body: notice.body, sender: 'Test Admin', recipient_count: 2, delivered: 1, read: 0, created_at: business.time }], prev_page_url: null, next_page_url: null } }); }
    else if (path.startsWith('/messages/')) { component = 'user/Message'; props.message = path.endsWith('2') ? notice : business; }
    else Object.assign(props, { messages: { data: empty ? [] : [notice, business], prev_page_url: null, next_page_url: '/messages?page=2' }, filter: 'all' });
    return { component, url, version: 'fixture', props };
}
await new Promise(r => server.listen(0, '127.0.0.1', r));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const base = `http://127.0.0.1:${server.address().port}`;
await mkdir('/tmp/card-inbox-browser', { recursive: true });
try {
    for (locale of ['zh-CN', 'en', 'ms', 'es']) for (const width of [375, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        page.on('pageerror', e => errors.push(e.message));
        count = 101; business.readAt = notice.readAt = null;
        await page.goto(base + '/account');
        await page.locator('a[href="/messages"]').last().waitFor();
        assert.equal(await page.getByText('99+', { exact: true }).count(), 3);
        await page.locator('a[href="/messages"]').last().click();
        await page.locator(`a[href="/messages/${notice.id}"]`).waitFor();
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `list overflow ${locale}/${width}`);
        await page.screenshot({ path: `/tmp/card-inbox-browser/list-${locale}-${width}.png`, fullPage: true });
        await page.locator(`a[href="/messages/${notice.id}"]`).click();
        await page.locator('article').waitFor();
        await page.waitForFunction(() => !document.body.textContent.includes('99+'));
        assert.ok((await page.locator('article').innerText()).includes('<script>'));
        assert.equal(await page.evaluate(() => window.injected), undefined);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `detail overflow ${locale}/${width}`);
        await page.goto(base + '/messages/' + business.id);
        await page.locator('article').waitFor();
        assert.ok((await page.locator('article').innerText()).includes('0.000000000000000001 ETH'));
        await page.close();
    }
    const page = await browser.newPage({ viewport: { width: 375, height: 900 } });
    page.on('pageerror', e => errors.push(e.message));
    locale = 'en'; count = 3; supportCount = 4;
    await page.goto(base + '/account');
    await page.locator('a[href="/support"]').last().waitFor();
    assert.ok((await page.locator('a[href="/support"]').last().innerText()).includes('4'));
    assert.ok((await page.locator('nav a[href="/account"]').innerText()).includes('7'));
    await page.screenshot({ path: '/tmp/card-inbox-browser/support-total-375.png', fullPage: true });
    await page.locator('a[href="/support"]').last().click();
    await page.getByText('Support reply', { exact: true }).waitFor();
    await page.waitForFunction(() => document.querySelector('nav a[href="/account"]')?.textContent.includes('3'));
    assert.equal(supportCount, 0);
    empty = true;
    await page.goto(base + '/messages'); await page.getByText('No messages yet').waitFor(); empty = false;
    failRead = true; business.readAt = null;
    await page.goto(base + '/messages/' + business.id); await page.getByRole('alert').waitFor();
    failRead = false; await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await page.getByRole('alert').waitFor({ state: 'detached' });
    await page.goto(base + '/platform/notifications');
    await page.getByText('Sent notifications', { exact: true }).waitFor();
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `admin overflow ${width}`);
        await page.screenshot({ path: `/tmp/card-inbox-browser/admin-${width}.png`, fullPage: true });
    }
    await page.getByLabel('Recipients', { exact: true }).selectOption('all');
    await page.getByLabel('Notification title', { exact: true }).fill('Fixture notification');
    await page.getByLabel('Notification content', { exact: true }).fill('<b>Literal text</b>');
    await page.getByRole('button', { name: 'Preview notification', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    assert.ok((await dialog.innerText()).includes('Test company'));
    assert.ok((await dialog.innerText()).includes('<b>Literal text</b>'));
    assert.equal(await dialog.getByRole('button', { name: 'Send notification', exact: true }).isEnabled(), false);
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('button', { name: 'Send notification', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    assert.equal(writes.filter(p => p === '/platform/tenants/fixture/notifications').length, 1);
    assert.deepEqual(errors, []);
    console.log('PASS: 4 locales × 3 sizes, badges/reads, native precision, plain text, empty/error/retry, admin responsive; all offline.');
} finally { await browser.close(); server.close(); }
