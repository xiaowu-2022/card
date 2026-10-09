import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function setup() {
    const exports = {}, requests = [], navigations = [];
    const preview = {};
    runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/cards-preview.ts', 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, { exports: preview });
    const signedIn = (tenant = 'tenant-a', id = 'user-a') => ({ tenant: { id: tenant, slug: 'company' }, user: { id }, unread: {}, locale: 'en' });
    const api = { native: false, company: { tenantSlug: 'company' }, sessionGeneration: 0,
        setToken() { api.sessionGeneration++; }, setCsrf() {}, clearFlow() {},
        ApiError: class extends Error {},
        request(path) {
            if (path === '/login') return Promise.resolve({});
            if (path === '/bootstrap') return Promise.resolve(signedIn());
            return new Promise((resolve, reject) => requests.push({ path, resolve, reject }));
        } };
    const source = ts.transpileModule(readFileSync('mobile/uni-app/src/lib/session.ts', 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(source, { exports, uni: { reLaunch: value => navigations.push(value.url) }, require: name => {
        if (name === 'vue') return { ref: value => ({ value }), reactive: value => value, computed: fn => ({ get value() { return fn(); } }) };
        if (name === './api') return api;
        if (name === './cards-preview') return preview;
        if (name === './webview-session') return { notifyWebviewSession() {} };
        if (name === './support-reminder') return { remindSupportUnread() {}, resetSupportReminder() {} };
        if (name === './origin') return { setPublicAssets() {} };
        if (name === './i18n') return { configureLocale() {} };
        throw new Error(name);
    } });
    exports.session.value = signedIn();
    return { ...exports, requests, navigations, signedIn, preview };
}

test('concurrent and subsequent page entries reuse successful wallet initialization', async () => {
    const s = setup();
    const first = s.requireUser(), second = s.requireUser();
    assert.equal(s.requests.length, 1);
    assert.equal(s.requests[0].path, '/wallet/ensure');
    s.requests[0].resolve();
    assert.equal(await first, true); assert.equal(await second, true);
    assert.equal(await s.requireUser(), true);
    assert.equal(s.requests.length, 1);
});

test('failed initialization remains retryable', async () => {
    const s = setup(), first = s.requireUser();
    s.requests[0].reject(new Error('offline'));
    await assert.rejects(first, /offline/);
    const next = s.requireUser();
    assert.equal(s.requests.length, 2);
    s.requests[1].resolve(); assert.equal(await next, true);
});

test('logout invalidates in-flight initialization even when the same user signs in again', async () => {
    const s = setup(), old = s.requireUser();
    s.clearSession(); s.session.value = s.signedIn();
    const next = s.requireUser();
    s.requests[0].resolve(); assert.equal(await old, false);
    const concurrent = s.requireUser(); assert.equal(s.requests.length, 2);
    s.requests[1].resolve(); assert.equal(await next, true); assert.equal(await concurrent, true);
});

test('tenant and user changes cannot reuse another identity readiness', async () => {
    const s = setup();
    for (const [tenant, user] of [['tenant-a', 'user-a'], ['tenant-a', 'user-b'], ['tenant-b', 'user-b']]) {
        s.session.value = s.signedIn(tenant, user);
        const pending = s.requireUser(); s.requests.at(-1).resolve(); assert.equal(await pending, true);
    }
    assert.equal(s.requests.length, 3);
});

test('successful login starts a fresh readiness scope even without a preceding logout', async () => {
    const s = setup(), first = s.requireUser();
    s.requests[0].resolve(); await first;
    await s.login('example@example.test', 'not-a-real-password');
    const next = s.requireUser(); assert.equal(s.requests.length, 2);
    s.requests[1].resolve(); assert.equal(await next, true);
});

test('card previews are isolated copies and discarded on logout, login and identity changes', async () => {
    const s = setup(), data = { cards: [{ balance: '10.25', maskedPan: '****1234' }] };
    const save = () => s.preview.saveCardsPreview(s.consumerSessionScope(), data);
    const read = () => s.preview.readCardsPreview(s.consumerSessionScope());
    save();
    data.cards[0].balance = '99';
    assert.equal(read().cards[0].balance, '10.25');
    const copy = read(); copy.cards[0].balance = '42';
    assert.equal(read().cards[0].balance, '10.25');
    s.clearSession(); s.session.value = s.signedIn(); assert.equal(read(), null);
    save(); await s.login('example@example.test', 'not-a-real-password'); assert.equal(read(), null);
    save(); s.session.value = s.signedIn('tenant-a', 'user-b'); assert.equal(read(), null);
    save(); s.session.value = s.signedIn('tenant-b', 'user-b'); assert.equal(read(), null);
    save(); s.session.value = null; assert.equal(read(), null);
});
