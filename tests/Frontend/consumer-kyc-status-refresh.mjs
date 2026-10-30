import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const flush = () => new Promise(setImmediate);
function coordinator({ status = 'QUEUED', reload = async () => {} } = {}) {
    const state = { status, busy: false, scope: 'offline-company:user' };
    const timers = new Map(), exports = {};
    let clock = 0, id = 0, calls = 0;
    runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/kyc-status-refresh.ts', 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, { exports,
        setTimeout: (fn, delay) => { timers.set(++id, { fn, at: clock + delay }); return id; },
        clearTimeout: id => timers.delete(id),
    });
    const refresh = exports.createKycStatusRefresh({
        status: () => state.status, busy: () => state.busy, scope: () => state.scope,
        reload: async guard => { calls++; await reload(guard); },
    });
    const advance = async duration => {
        const end = clock + duration;
        for (;;) {
            const next = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            clock = next[1].at; timers.delete(next[0]); next[1].fn(); await flush();
        }
        clock = end; await flush();
    };
    return { state, refresh, timers, advance, calls: () => calls };
}

test('only visible pending KYC reads automatically and terminal status stops the timer', async () => {
    for (const status of ['QUEUED', 'PROCESSING', 'WAITING_REVIEW']) {
        const s = coordinator({ status });
        await s.advance(60000); assert.equal(s.calls(), 0);
        s.refresh.setVisible(true);
        await s.advance(9999); assert.equal(s.calls(), 0);
        await s.advance(1); assert.equal(s.calls(), 1);
        s.state.status = null; s.refresh.sync();
        await s.advance(60000); assert.equal(s.calls(), 1); assert.equal(s.timers.size, 0);
    }
    for (const status of ['FAILED', 'COMPLETE', null]) {
        const s = coordinator({ status }); s.refresh.setVisible(true);
        await s.advance(60000); assert.equal(s.calls(), 0);
        await s.refresh.refresh(); assert.equal(s.calls(), 1); // Explicit refresh remains available.
    }
});

test('manual and automatic reads share one flight, pause during submit, and reject pre-submit evidence', async () => {
    let complete, guard;
    const s = coordinator({ reload: current => { guard = current; return new Promise(resolve => complete = resolve); } });
    s.refresh.setVisible(true); await s.advance(10000);
    const manual = s.refresh.refresh();
    assert.equal(s.calls(), 1);
    s.state.busy = true; s.refresh.sync();
    assert.equal(guard(), false);
    await s.advance(60000); assert.equal(s.calls(), 1);
    s.state.busy = false; s.refresh.sync();
    assert.equal(guard(), false); // Completing submission cannot revive older read evidence.
    complete(); await manual;
    await s.advance(10000); assert.equal(s.calls(), 2);
    complete(); await flush(); s.refresh.dispose();
});

test('hiding or leaving invalidates reads; pending foreground return reads once and deduplicates lifecycle events', async () => {
    let complete, guard;
    const s = coordinator({ reload: current => { guard = current; return new Promise(resolve => complete = resolve); } });
    s.refresh.setVisible(true); await s.advance(10000);
    s.refresh.setVisible(false); assert.equal(guard(), false);
    await s.advance(60000); assert.equal(s.calls(), 1);
    s.refresh.setVisible(true); s.refresh.setVisible(true);
    complete(); await flush(); assert.equal(s.calls(), 2);
    s.state.scope = 'other-company:other-user'; s.refresh.sync(); assert.equal(guard(), false);
    complete(); await flush(); await s.advance(60000); assert.equal(s.calls(), 2);
    s.refresh.dispose(); assert.equal(s.timers.size, 0);
});

test('automatic reads have a finite budget, reset only on explicit refresh or a new visible window, and failures back off', async () => {
    const s = coordinator(); s.refresh.setVisible(true);
    await s.advance(300000); assert.equal(s.calls(), 30);
    await s.advance(29999); assert.equal(s.calls(), 30);
    await s.advance(1); assert.equal(s.calls(), 31);
    await s.advance(1800000); assert.equal(s.calls(), 60); assert.equal(s.timers.size, 0);
    await s.refresh.refresh(); assert.equal(s.calls(), 61);
    await s.advance(10000); assert.equal(s.calls(), 62);
    s.refresh.setVisible(false); s.refresh.setVisible(true); await flush(); assert.equal(s.calls(), 63);
    s.refresh.dispose(); await s.advance(1800000); assert.equal(s.calls(), 63);

    const failed = coordinator({ reload: async () => { throw Error('offline'); } });
    failed.refresh.setVisible(true);
    await failed.advance(10000); assert.equal(failed.calls(), 1);
    await failed.advance(19999); assert.equal(failed.calls(), 1);
    await failed.advance(1); assert.equal(failed.calls(), 2);
    await failed.advance(40000); assert.equal(failed.calls(), 3);
    await failed.advance(59999); assert.equal(failed.calls(), 3);
    await failed.advance(1); assert.equal(failed.calls(), 4);
    failed.refresh.dispose();
});

function screen() {
    const hooks = {}, exports = {}, requests = [];
    let scope = 'offline-company:user';
    class ApiError extends Error { constructor(status) { super('offline'); this.status = status; } }
    const source = readFileSync('mobile/uni-app/src/pages/screen/index.vue', 'utf8').match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
    runInNewContext(ts.transpileModule(source + '\nexport { data, loading, refreshKycStatus, load };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, { exports, document: { hidden: false }, uni: {}, require: id => {
        if (id === 'vue') return { ref: value => ({ value }), nextTick: async () => {}, onErrorCaptured() {}, computed: getter => ({ get value() { return getter(); } }), watch() {} };
        if (id === '@dcloudio/uni-app') return { onLoad: fn => hooks.load = fn, onShow() {}, onHide: fn => hooks.hide = fn, onPageScroll() {} };
        if (id.endsWith('/lazy-screen')) return { lazyScreen: () => ({}) };
        if (id.endsWith('/page-position')) return { usePagePosition() {} };
        if (id.endsWith('/session')) return { consumerSessionScope: () => scope, clearSession: () => scope = '' };
        if (id.endsWith('/client')) return { ensureBootstrap: async () => {}, explainError: () => ({ form: 'offline' }), getPage: (path, options) => new Promise((resolve, reject) => requests.push({ path, options, resolve, reject })) };
        if (id.endsWith('/api')) return { ApiError, setCurrentPage() {} };
        if (id.endsWith('/navigation')) return { go() {} };
        return {};
    } });
    hooks.load({ path: '/kyc?from=account-security' });
    const original = { component: 'user/Kyc', props: { kyc: { status: 'PENDING', processingStatus: 'PROCESSING' } } };
    exports.data.value = original; exports.loading.value = false;
    return { ...exports, requests, hooks, original, ApiError, changeScope: value => scope = value };
}

test('quiet refresh performs a background GET without bootstrap/loading/unmount and retains the page on transient errors', async () => {
    const s = screen();
    const first = s.refreshKycStatus(() => true), same = s.refreshKycStatus(() => true);
    assert.equal(first, same); await flush(); assert.equal(s.requests.length, 1);
    assert.equal(s.requests[0].path, '/kyc?from=account-security'); assert.equal(s.requests[0].options.background, true);
    assert.equal(s.loading.value, false); assert.equal(s.data.value, s.original);
    s.requests[0].reject(new s.ApiError(503)); await assert.rejects(first);
    assert.equal(s.data.value, s.original); assert.equal(s.loading.value, false);
    const next = s.refreshKycStatus(() => true); await flush();
    s.requests[1].resolve({ component: 'user/Kyc', props: { kyc: { status: 'APPROVED', processingStatus: null } } }); await next;
    assert.equal(s.data.value.component, 'user/Kyc'); assert.equal(s.data.value.props.kyc.status, 'APPROVED');
    assert.equal(s.loading.value, false);
});

test('quiet refresh cannot apply after submit/hide, logout/company change or navigation; confirmed denial clears evidence', async () => {
    const beforeSend = screen();
    const cancelled = beforeSend.refreshKycStatus(() => true);
    beforeSend.changeScope('other-company:user'); await cancelled;
    assert.equal(beforeSend.requests.length, 0, 'scope changes before the queued send cancel the old read');
    for (const invalidate of [s => s.changeScope('other-company:user'), s => s.hooks.hide()]) {
        const s = screen(), read = s.refreshKycStatus(() => true); await flush();
        invalidate(s); s.requests[0].resolve({ component: 'user/Kyc', props: { kyc: { status: 'APPROVED' } } }); await read;
        assert.equal(s.data.value, s.original);
    }
    const s = screen(); let current = true;
    const hidden = s.refreshKycStatus(() => current); await flush(); current = false;
    s.requests[0].resolve({ redirect: '/unexpected' }); await hidden; assert.equal(s.data.value, s.original);
    const denied = s.refreshKycStatus(() => true); await flush(); s.requests[1].reject(new s.ApiError(401)); await assert.rejects(denied);
    assert.equal(s.data.value, null);
});

test('quiet malformed responses back off instead of succeeding, while a restricted response removes KYC access', async () => {
    const s = screen();
    for (const response of [
        { component: 'unexpected', props: {} },
        { component: 'user/Kyc', props: {} },
        { component: 'user/Kyc', props: { kyc: { status: 'PENDING', processingStatus: {} } } },
    ]) {
        const malformed = s.refreshKycStatus(() => true); await flush();
        s.requests.at(-1).resolve(response); await assert.rejects(malformed);
        assert.equal(s.data.value, s.original);
    }
    const restricted = s.refreshKycStatus(() => true); await flush();
    s.requests.at(-1).resolve({ component: 'user/Restricted', props: { reason: 'offline-access-denied' } }); await restricted;
    assert.equal(s.data.value.component, 'user/Restricted');
});

test('non-H5 KYC manual refresh keeps the existing reload action without enabling automatic reads', () => {
    const source = readFileSync('mobile/uni-app/src/screens/Kyc.vue', 'utf8').match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1]
        .replace(/^[ \t]*\/\/ #ifdef H5\n[\s\S]*?^[ \t]*\/\/ #endif\n/gm, '');
    const exports = {}, events = [];
    runInNewContext(ts.transpileModule(source + '\nexport { refreshStatus };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, { exports, Intl,
        defineProps: () => ({ page: { kyc: { status: 'PENDING' } }, refreshStatus: () => { throw Error('native quiet refresh must remain inactive'); } }),
        defineEmits: () => event => events.push(event),
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }), watch() {}, onMounted: fn => fn(), onBeforeUnmount() {} };
            if (id === '@dcloudio/uni-app') return { onShow: fn => fn(), onHide() {} };
            if (id.endsWith('/kyc-status-refresh')) return { createKycStatusRefresh: () => ({ sync() {}, dispose() {}, refresh: () => { throw Error('native poll must remain inactive'); } }) };
            if (id.endsWith('/session')) return { consumerSessionScope: () => 'offline-company:user' };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen() {} };
            if (id.endsWith('/client')) return { useAction: () => ({ pending: { value: false } }) };
            return {};
        },
    });
    exports.refreshStatus(); assert.deepEqual(events, ['reload']);
});
