import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const compiled = ts.transpileModule(fs.readFileSync('resources/js/components/support/AdminSupportReminder.tsx', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(setImmediate); };
function harness(initialCount = 2) {
    const document = new EventTarget();
    document.hidden = false;
    const window = new EventTarget();
    const timers = new Set();
    let push, connected = false;
    let effect, count = initialCount, fail = false, now = 100000, plays = 0, primes = 0, pauses = 0, visibleCount = 0, player;
    class Audio {
        paused = true;
        muted = false;
        constructor() { player = this; }
        play() { if (this.muted) primes++; else plays++; this.paused = false; return Promise.resolve(); }
        pause() { pauses++; this.paused = true; }
    }
    const react = {
        createContext: () => ({ Provider: 'provider' }), useContext: () => visibleCount,
        useState: () => [visibleCount, (v) => { visibleCount = v; }], useRef: () => ({ current: null }),
        useEffect: (fn) => { effect = fn; },
    };
    const exports = {};
    vm.runInNewContext(compiled, {
        exports, Audio, Event, document, window, AbortController,
        Date: { now: () => now },
        fetch: async () => ({ ok: !fail, redirected: false, json: async () => ({ count }) }),
        require: (name) => name === 'react' ? react : name === 'react/jsx-runtime' ? { jsx: () => null } : name === '@inertiajs/react' ? {
            usePage: () => ({ props: { auth: { admin: { id: 'fixture-admin', permissions: ['support.read'] } }, tenant: null } }),
        } : name === '@/lib/supportRealtime' ? { subscribeSupportRealtime: (fn) => { push = fn; return () => { push = undefined; }; }, supportRealtimeConnected: () => connected } : { t: (s) => s },
        clearInterval: (id) => timers.delete(id),
    });
    window.setInterval = (fn) => { timers.add(fn); return fn; };
    exports.AdminSupportReminder({ children: null, platform: true });
    const cleanup = effect();
    return { document, window, cleanup, push: async () => { push?.(); await flush(); }, set connected(v) { connected = v; }, tick: async () => { for (const fn of timers) fn(); await flush(); },
        finish: () => { player.paused = true; },
        read: () => window.dispatchEvent(new Event('admin-support-read')),
        unlock: () => document.dispatchEvent(new Event('pointerdown')),
        get plays() { return plays; }, get primes() { return primes; }, get pauses() { return pauses; }, get visibleCount() { return visibleCount; },
        set count(v) { count = v; }, set fail(v) { fail = v; }, set now(v) { now = v; },
    };
}

test('admin reminders require interaction and fresh unread counters, repeat at most once per minute', async () => {
    const h = harness();
    await flush();
    assert.equal(h.visibleCount, 2);
    assert.equal(h.plays, 0);
    h.unlock();
    assert.equal(h.plays, 1);
    h.finish();
    h.document.hidden = true;
    h.document.dispatchEvent(new Event('visibilitychange'));
    h.document.hidden = false;
    h.document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    h.now = 159999;
    await h.tick();
    assert.equal(h.plays, 1);
    h.now = 160000;
    await h.tick();
    assert.equal(h.plays, 2);
    h.count = 0;
    h.read();
    await flush();
    assert.equal(h.visibleCount, 0);
    const before = h.plays;
    h.unlock();
    assert.equal(h.plays, before);
    h.cleanup();
});

test('failed counters and disposal suppress audio while hidden-tab recovery can remind', async () => {
    const h = harness();
    await flush();
    h.fail = true;
    await h.tick();
    assert.equal(h.visibleCount, 0);
    h.unlock();
    await flush();
    assert.equal(h.plays, 0);
    h.fail = false;
    h.document.hidden = true;
    await h.tick();
    assert.equal(h.plays, 1);
    h.document.hidden = false;
    h.document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    assert.equal(h.plays, 1);
    h.cleanup();
    const pauses = h.pauses;
    h.unlock();
    await h.tick();
    assert.equal(h.plays, 1);
    assert.equal(h.pauses, pauses);
});

 test('new customer messages bypass the minute delay, unchanged unread repeats after a minute', async () => {
    const h = harness();
    await flush();
    h.unlock();
    h.finish();
    h.now = 105000;
    h.count = 3;
    await h.tick();
    assert.equal(h.plays, 2);
    h.finish();
    h.now = 110000;
    await h.tick();
    assert.equal(h.plays, 2);
    h.now = 165000;
    await h.tick();
    assert.equal(h.plays, 3);
    h.cleanup();
});


test('WebSocket notifications immediately update admin badge and sound while connected fallback reads pause', async () => {
    const h = harness(); await flush(); h.unlock(); h.finish();
    h.connected = true; h.count = 3; h.now = 105000;
    await h.tick(); assert.equal(h.visibleCount, 2, 'healthy socket pauses five-second counter reads');
    await h.push(); assert.equal(h.visibleCount, 3); assert.equal(h.plays, 2, 'push immediately triggers new-message sound');
    h.read(); await flush(); h.count = 0; await h.push();
    assert.equal(h.visibleCount, 0);
    h.cleanup(); h.count = 9; await h.push(); assert.equal(h.visibleCount, 0, 'disposed listener cannot update');
});


test('a hidden admin tab keeps sound, receives pushes, repeats unread and never marks messages read', async () => {
    const h = harness(0); await flush();
    h.unlock(); await flush();
    assert.equal(h.primes, 1, 'gesture primes the same audio element before the first message');
    assert.equal(h.plays, 0);
    h.connected = true;
    h.document.hidden = true; h.document.dispatchEvent(new Event('visibilitychange')); await flush();
    h.count = 1; h.now = 105000; await h.push();
    assert.equal(h.visibleCount, 1); assert.equal(h.plays, 1, 'hidden-tab push sounds immediately');
    const pauses = h.pauses;
    h.document.dispatchEvent(new Event('visibilitychange')); await flush();
    assert.equal(h.pauses, pauses, 'switching tabs does not interrupt ongoing sound');
    h.finish(); h.now = 110000; h.count = 2; await h.push();
    assert.equal(h.plays, 2, 'another background arrival bypasses repeat cooldown');
    h.finish(); h.now = 170000; await h.tick(); assert.equal(h.plays, 3, 'unread can repeat in the background');
    h.count = 0; await h.push(); assert.equal(h.visibleCount, 0);
    h.cleanup(); const before = h.plays; h.count = 4; await h.push(); await h.tick(); assert.equal(h.plays, before);
});
