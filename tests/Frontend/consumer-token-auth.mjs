import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const token = '12|' + 'A'.repeat(64), other = '13|' + 'B'.repeat(64);
const channel = 'C'.repeat(64), host = 'https://a.example.test';
function compile(path, replace = value => value) {
    return ts.transpileModule(replace(readFileSync(path, 'utf8')), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
}
function browser(webview = false) {
    const exports = {}, storage = new Map(), requests = [], listeners = new Map();
    const window = { addEventListener: (name, listener) => listeners.set(name, listener), removeEventListener: name => listeners.delete(name), dispatchEvent: event => listeners.get(event.type)?.(event) };
    const localStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
    runInNewContext(compile('mobile/uni-app/src/lib/browser-credentials.ts', value => value.replaceAll('import.meta.env.UNI_PLATFORM', '"h5"')), {
        exports, window, localStorage, location: { search: webview ? '?app_webview=1' : '' }, URLSearchParams, setTimeout, clearTimeout, AbortController, CustomEvent,
        fetch: async (url, options) => { requests.push({ url, ...options }); return { ok: true, json: async () => ({ revision: requests.length }) }; },
    });
    return { api: exports, window, storage, requests, listeners, localStorage };
}
test('ordinary H5 restores and clears its current-origin token without touching cookies', async () => {
    const b = browser();
    assert.equal(await b.api.loadBrowserToken(), null);
    b.api.saveBrowserToken(token);
    assert.equal(await b.api.loadBrowserToken(), token);
    await b.api.publishBrowserToken(token, 'company');
    assert.equal(b.requests.length, 0);
    b.api.saveBrowserToken(null);
    assert.equal(await b.api.loadBrowserToken(), null);
});
test('WebView waits for its document bridge, stores no browser token and publishes only in an authenticated POST', async () => {
    const b = browser(true);
    const restoring = b.api.loadBrowserToken();
    assert.equal(b.storage.size, 0);
    b.window.__consumerTokenBridge = { token, channel, tenantId: 'company' };
    b.listeners.get('consumer-token-bridge')();
    assert.equal(await restoring, token);
    assert.equal(b.window.__consumerTokenBridge.token, null);
    b.api.saveBrowserToken(other);
    assert.equal(b.window.__consumerSessionState.pending, true);
    assert.equal(b.storage.size, 0);
    await b.api.publishBrowserToken(other, 'company');
    assert.equal(b.requests[0].url, '/api/mobile/v1/session/bridge/publish');
    assert.equal(b.requests[0].credentials, 'omit');
    assert.equal(b.requests[0].method, 'POST');
    assert.equal(b.requests[0].headers.Authorization, 'Bearer ' + other);
    assert.equal(b.api.credentialRevision, 1);
    await assert.rejects(b.api.publishBrowserToken(other, 'foreign'), /Company/);
    assert.equal(b.requests.length, 1);
});
test('storage notifications read the newest value and propagate logout across browser tabs', () => {
    const b = browser(), values = [];
    b.api.onBrowserTokenChange(value => values.push(value));
    b.api.saveBrowserToken(other);
    b.listeners.get('storage')({ key: 'consumer-bearer-v1', newValue: token, storageArea: b.localStorage });
    b.api.saveBrowserToken(null);
    b.listeners.get('storage')({ key: 'consumer-bearer-v1', newValue: null, storageArea: b.localStorage });
    assert.deepEqual(values, [other, null]);
});
function native() {
    const exports = {}, directory = {};
    runInNewContext(compile('mobile/webview-shell/src/lib/directory.ts'), { exports: directory, setTimeout, clearTimeout });
    runInNewContext(compile('mobile/webview-shell/src/lib/token-session.ts'), {
        exports, require: name => name === './directory' ? directory : { LoginRestoreUnavailable: class extends Error {} },
    });
    let saved = token, metadata = { signedIn: true }, reply = token, captures = 0;
    const ports = {
        vault: { read: () => saved, write: value => { saved = value; } },
        metadata: { get: () => metadata, set: value => { metadata = value; } },
        verify: async (_host, value) => ({ tenantId: 'company', signedIn: !!value, restricted: false }),
        open: async () => channel,
        claim: async () => { captures++; return reply; },
    };
    return { create: () => exports.tokenSession(ports), ports, saved: () => saved, metadata: () => metadata,
        reply: value => { reply = value; }, captures: () => captures };
}
const listing = { tenantId: 'company', origins: [host, 'https://b.example.test'], selected: host };
test('native restoration verifies company membership before sending a token and never puts it in a URL or title', async () => {
    const n = native(), s = n.create();
    await s.configure(listing);
    await assert.rejects(s.restore('https://foreign.example.test'), /Unverified/);
    assert.equal((await s.restore(host)).signedIn, true);
    const script = s.script(host, 'document-one');
    assert.ok(script.includes(token));
    assert.equal(script.includes('document.title'), false);
    assert.equal(script.includes('location.href'), false);
    assert.equal(script.includes('localStorage'), false);
    await assert.rejects(s.configure({ ...listing, tenantId: 'foreign' }), /Company/);
});
test('a newer account is saved once; late captures after logout cannot resurrect it', async () => {
    const n = native(), s = n.create();
    await s.configure(listing); await s.restore(host);
    n.reply(other);
    assert.equal(await s.capture(host, 1, () => true), true);
    assert.equal(n.saved(), other);
    assert.equal(await s.capture(host, 1, () => true), true);
    assert.equal(n.captures(), 1);
    let release;
    n.ports.claim = () => new Promise(resolve => { release = resolve; });
    const pending = s.capture(host, 2, () => true);
    s.clear(); release(token);
    assert.equal(await pending, false);
    assert.equal(n.saved(), null);
    assert.equal(n.metadata().signedIn, false);
});
test('failed capture of a changed account blocks restoring the previous account but preserves encrypted bytes for retry', async () => {
    const n = native(), s = n.create();
    await s.configure(listing); await s.restore(host);
    n.ports.claim = async () => { throw Error('offline'); };
    await assert.rejects(s.capture(host, 1, () => true), /offline/);
    assert.equal(n.saved(), token);
    assert.equal(n.metadata().recovering, true);
    await assert.rejects(s.restore(host));
    await assert.rejects(n.create().configure(listing));
    n.ports.claim = async () => other;
    assert.equal(await s.capture(host, 1, () => true), true);
    assert.equal(n.saved(), other);
});
test('a pending login fences the previous native credential even before bootstrap publishes the new account', async () => {
    const n = native(), s = n.create();
    await s.configure(listing); await s.restore(host);
    s.pending();
    await assert.rejects(s.restore(host));
    assert.equal(n.saved(), token, 'encrypted evidence is retained for recovery, not reused');
    n.reply(other);
    assert.equal(await s.capture(host, 1, () => true), true);
    assert.equal(n.saved(), other);
});
test('transient restoration failures retain credentials; only a confirmed guest clears expiry', async () => {
    const n = native(), s = n.create();
    await s.configure(listing);
    n.ports.verify = async () => { throw Error('offline'); };
    await assert.rejects(s.restore(host), /offline/);
    assert.equal(n.saved(), token);
    n.ports.verify = async () => ({ tenantId: 'foreign', signedIn: false, restricted: false });
    await assert.rejects(s.restore(host));
    assert.equal(n.saved(), token);
    n.ports.verify = async () => ({ tenantId: 'company', signedIn: false, restricted: false });
    assert.equal((await s.restore(host)).signedIn, false);
    assert.equal(n.saved(), null);
});
test('native save failure retains the current mailbox and retries the same credential without restoring an older account', async () => {
    const n = native(), s = n.create();
    await s.configure(listing); await s.restore(host); s.pending(); n.reply(other);
    const write = n.ports.vault.write;
    n.ports.vault.write = () => { throw Error('locked storage'); };
    await assert.rejects(s.capture(host, 1, () => true), /locked storage/);
    assert.equal(s.captureStage(), 'storage');
    assert.equal(n.metadata().recovering, true);
    await assert.rejects(s.restore(host));
    n.ports.vault.write = write;
    assert.equal(await s.capture(host, 1, () => true), true);
    assert.equal(s.captureStage(), 'ready');
    assert.equal(n.saved(), other);
    assert.equal(n.metadata().recovering, undefined);
});
test('capture diagnostics distinguish mailbox and identity failures without exposing errors or credentials', async () => {
    const n = native(), s = n.create();
    await s.configure(listing); await s.restore(host);
    n.ports.claim = async () => { throw Error('private server error'); };
    await assert.rejects(s.capture(host, 1, () => true));
    assert.equal(s.captureStage(), 'claim');
    n.ports.claim = async () => other;
    n.ports.verify = async () => { throw Error('private identity error'); };
    await assert.rejects(s.capture(host, 1, () => true));
    assert.equal(s.captureStage(), 'verify');
});
