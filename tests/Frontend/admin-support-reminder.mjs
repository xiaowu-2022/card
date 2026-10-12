import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const compiled = ts.transpileModule(fs.readFileSync('resources/js/components/support/AdminSupportReminder.tsx', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(setImmediate); };
function harness() {
    const document = new EventTarget();
    document.hidden = false;
    const window = new EventTarget();
    const timers = new Set();
    let effect, count = 2, fail = false, now = 100000, plays = 0, pauses = 0, visibleCount = 0;
    class Audio {
        paused = true;
        play() { plays++; this.paused = false; return Promise.resolve(); }
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
        } : { t: (s) => s },
        clearInterval: (id) => timers.delete(id),
    });
    window.setInterval = (fn) => { timers.add(fn); return fn; };
    exports.AdminSupportReminder({ children: null, platform: true });
    const cleanup = effect();
    return { document, window, cleanup, tick: async () => { for (const fn of timers) fn(); await flush(); },
        read: () => window.dispatchEvent(new Event('admin-support-read')),
        unlock: () => document.dispatchEvent(new Event('pointerdown')),
        get plays() { return plays; }, get pauses() { return pauses; }, get visibleCount() { return visibleCount; },
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

test('failed counters, background and disposal suppress audio and clear unread state', async () => {
    const h = harness();
    await flush();
    h.fail = true;
    await h.tick();
    assert.equal(h.visibleCount, 0);
    h.unlock();
    assert.equal(h.plays, 0);
    h.fail = false;
    h.document.hidden = true;
    await h.tick();
    assert.equal(h.plays, 0);
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
