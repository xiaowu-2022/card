import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
import ts from 'typescript';
import { selectWebviewEntry } from '../../scripts/client/webview-entry.mjs';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')).authenticated;
const origin = 'http://127.0.0.1:5249';
const compile = path => ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
for (const [name, engine, options] of [['chromium', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        for (const [mode, authenticated] of [['legacy', false], ['legacy', true], ['token', false], ['token', true]]) {
            const page = await browser.newPage({ viewport: { width: 390, height: 844 } }), requests = [], errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.origin !== origin) return route.abort();
                if (url.pathname.startsWith('/api/')) {
                    requests.push(url.pathname);
                    if (url.pathname.endsWith('/session/bridge/publish')) return route.fulfill({ json: { revision: 1 } });
                    if (url.pathname === '/api/mobile/v1/client/account/restricted' || url.pathname === '/api/v1/client/account/restricted') return route.fulfill({ json: { component: 'user/Restricted', props: { tenantRestricted: false } } });
                    if (url.pathname.endsWith('/bootstrap')) return route.fulfill({ json: {
                        ...fixture, user: authenticated ? fixture.user : null, restricted: authenticated, unread: null, publicAssets: {},
                    }, headers: { 'X-Consumer-Flow': 'F'.repeat(64) } });
                    return route.fulfill({ json: {} });
                }
                const path = 'public/h5' + (url.pathname === '/' ? '/index.html' : url.pathname);
                try { return route.fulfill({ body: readFileSync(path), contentType: path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html' }); }
                catch { return route.abort(); }
            });
            const search = '?app_webview=1' + (mode === 'token' ? '&native_auth=token-v1' : '');
            assert.equal(selectWebviewEntry(search), mode === 'legacy');
            await page.goto(origin + '/' + search + '#/pages/login/index');
            if (mode === 'token') {
                // A native evalJS before document readiness may be dropped. The
                // later retry must unblock H5 without any browser persistence.
                await page.evaluate(({ tenantId, authenticated }) => {
                    window.__consumerTokenBridge = { tenantId, channel: 'C'.repeat(64), token: authenticated ? '12|' + 'A'.repeat(64) : null };
                    window.dispatchEvent(new Event('consumer-token-bridge'));
                }, { tenantId: fixture.tenant.id, authenticated });
            }
            await page.locator(authenticated ? '.restricted-title' : 'input[name="password"]').waitFor({ timeout: 5000 });
            await page.evaluate('(function(){var exports={};' + compile('mobile/webview-shell/src/lib/session-signal.ts') + ';window.sessionSignalScript=exports.sessionSignalScript;})();');
            await page.evaluate(() => { window.seen = []; new MutationObserver(() => window.seen.push(document.title)).observe(document.querySelector('title'), { childList: true }); });
            await page.evaluate(() => eval(window.sessionSignalScript('specpay-session-test:')));
            await page.waitForFunction(() => window.seen.some(title => title.startsWith('specpay-session-test:')));
            const state = await page.evaluate(() => window.__consumerSessionState);
            assert.equal(state.tenantId, fixture.tenant.id);
            assert.equal(state.signedIn, authenticated);
            if (mode === 'token' && authenticated) assert.equal(state.credentialRevision, 1);
            assert.ok(requests.includes(mode === 'token' ? '/api/mobile/v1/bootstrap' : '/api/v1/bootstrap'));
            assert.equal(requests.some(path => path.startsWith(mode === 'token' ? '/api/v1/' : '/api/mobile/v1/')), false);
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`PASS ${name} ${mode} ${authenticated ? 'restored' : 'guest'}: matching transport, login ready, native identity signal`);
        }
    } finally { await browser.close(); }
}
