import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';

const root = process.env.CONSUMER_H5_TEST_ROOT ?? 'mobile/uni-app/dist/build/h5', origin = 'http://127.0.0.1:5249';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8'));
const token = '12|' + 'A'.repeat(64), channel = 'C'.repeat(64);
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage(), errors = [], requests = [];
        page.on('pageerror', error => errors.push(error.message));
        let expired = false, offline = false, restoredFromNative = false;
        await context.addCookies([{ name: 'consumer_remember', value: 'old-cookie-must-not-authenticate', url: origin }]);
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin !== origin) return route.abort();
            if (url.pathname.startsWith('/api/')) {
                assert.ok(url.pathname.startsWith('/api/mobile/v1/'), 'all H5 business calls use bearer transport');
                const path = url.pathname.slice('/api/mobile/v1'.length), headers = request.headers();
                assert.equal(headers.cookie, undefined, 'browser API calls omit even existing cookies');
                assert.equal(headers['x-csrf-token'], undefined);
                requests.push({ path, method: request.method(), authorization: headers.authorization });
                if (path === '/login') return route.fulfill({ status: 201, json: { token } });
                if (headers.authorization && expired) return route.fulfill({ status: 401, json: {} });
                if (path === '/logout') { expired = true; return route.fulfill({ status: 204 }); }
                if (path === '/session/bridge/publish') {
                    assert.equal(headers.authorization, 'Bearer ' + token);
                    assert.equal(headers['x-consumer-bridge'], channel);
                    restoredFromNative = true;
                    return route.fulfill({ json: { revision: 1 } });
                }
                const signedIn = headers.authorization === 'Bearer ' + token;
                if (path === '/bootstrap') {
                    if (offline) return route.fulfill({ status: 503, json: {} });
                    return route.fulfill({ json: { ...fixture.authenticated, locale: 'en', csrfToken: null,
                        restricted: signedIn, user: signedIn ? fixture.authenticated.user : null, publicAssets: {}, unread: null } });
                }
                if (path === '/session') return route.fulfill({ json: { tenant: fixture.authenticated.tenant,
                    user: signedIn ? { id: fixture.authenticated.user.id } : null, restricted: signedIn } });
                if (path === '/client/account/restricted') return route.fulfill({ json: {
                    component: 'user/Restricted', props: { tenantRestricted: false },
                } });
                if (path === '/client/account/settings') return route.fulfill({ json: fixture.pages['/account/settings'] });
                return route.fulfill({ json: { messages: 0, support: 0 } });
            }
            const path = root + (url.pathname === '/' ? '/index.html' : url.pathname);
            try { return route.fulfill({ body: readFileSync(path), contentType: path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : path.endsWith('.svg') ? 'image/svg+xml' : 'text/html' }); }
            catch { return route.abort(); }
        });
        await page.goto(origin + '/#/pages/login/index');
        await page.locator('input[name="email"]').fill('offline@example.test');
        await page.locator('input[name="password"]').fill('offline-password');
        await page.locator('.auth-submit').click();
        await page.locator('.restricted-title').waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('consumer-bearer-v1')), token);
        assert.ok(requests.some(request => request.path === '/login' && !request.authorization));
        await page.reload();
        await page.locator('.restricted-title').waitFor();
        assert.equal(await page.locator('input[name="password"]').count(), 0);
        offline = true;
        await page.goto(origin + '/#/pages/login/index');
        await page.locator('.login-restoring button, .login-restoring uni-button').waitFor();
        assert.equal(await page.locator('input[name="password"]').count(), 0);
        assert.equal(await page.evaluate(() => localStorage.getItem('consumer-bearer-v1')), token);
        offline = false;
        await page.locator('.login-restoring button, .login-restoring uni-button').click();
        await page.locator('.restricted-title').waitFor();
        await page.goto(origin + '/#/pages/screen/index?path=%2Faccount%2Fsettings');
        await page.locator('.signout').click();
        await page.locator('input[name="password"]').waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('consumer-bearer-v1')), null);
        assert.equal(requests.find(request => request.path === '/logout').authorization, 'Bearer ' + token);
        await page.evaluate(token => localStorage.setItem('consumer-bearer-v1', token), token);
        await page.reload();
        await page.locator('input[name="password"]').waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('consumer-bearer-v1')), null, 'a server-revoked token is cleared before guest confirmation');
        // A restored native credential is passed in memory only, with no browser persistence.
        expired = false;
        await page.addInitScript(({ token, channel, tenantId }) => {
            window.__consumerTokenBridge = { token, channel, tenantId };
        }, { token, channel, tenantId: fixture.authenticated.tenant.id });
        await page.goto(origin + '/?app_webview=1#/pages/login/index');
        await page.locator('.restricted-title').waitFor();
        assert.equal(restoredFromNative, true);
        assert.equal(await page.evaluate(() => localStorage.getItem('consumer-bearer-v1')), null);
        assert.equal(await page.evaluate(() => window.__consumerTokenBridge.token), null);
        assert.deepEqual(errors, []);
        await context.close();
        console.log('PASS ' + name + ': bearer login, reload, offline retry, revocation, native memory restoration, no cookies');
    } finally { await browser.close(); }
}
