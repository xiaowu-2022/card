import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
const compiled = ts.transpileModule(readFileSync('shared/support-realtime.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(setImmediate); };
function harness({ authFails = false, configPending = false } = {}) {
    let now = 1000, nextTimer = 1, changes = 0, configs = 0, resolveConfig;
    const timers = new Map(), sockets = [], authorizations = [];
    const exports = {};
    vm.runInNewContext(compiled, {
        exports, Date: { now: () => now }, Math,
        setTimeout: (fn, delay) => { const id = nextTimer++; timers.set(id, { fn, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        setInterval: (fn, delay) => { const id = nextTimer++; timers.set(id, { fn, delay, interval: true }); return id; },
        clearInterval: id => timers.delete(id),
    });
    const config = { enabled: true, path: '/app/offline-key', channels: ['private-support.user.abc', 'private-support.team.def'] };
    const connection = exports.createSupportRealtime({
        origin: () => 'https://company.example',
        configuration: async () => { configs++; return configPending ? await new Promise(resolve => { resolveConfig = resolve; }) : config; },
        authorize: async (id, channel) => { authorizations.push([id, channel]); if (authFails) throw new Error(); return { auth: 'offline-key:signed' }; },
        connect: (url, handlers) => {
            const entry = { url, handlers, sent: [], closed: false };
            sockets.push(entry);
            return { send: data => entry.sent.push(JSON.parse(data)), close: () => { entry.closed = true; handlers.close(); } };
        },
        changed: () => { changes++; }, status: () => {},
    });
    function frame(event, data, channel) { sockets.at(-1).handlers.message(JSON.stringify({ event, data: JSON.stringify(data), channel })); }
    async function establish() {
        frame('pusher:connection_established', { socket_id: '123.456' }); await flush();
        for (const channel of config.channels) frame('pusher_internal:subscription_succeeded', {}, channel);
    }
    async function timer(delay) {
        const entry = [...timers.entries()].find(([, v]) => typeof delay === 'function' ? delay(v.delay) : v.delay === delay);
        assert.ok(entry, `timer ${delay} exists`);
        if (!entry[1].interval) timers.delete(entry[0]);
        entry[1].fn(); await flush();
    }
    return { connection, config, sockets, authorizations, frame, establish, timer, exports, timers,
        resolveConfig: () => resolveConfig(config), get configs() { return configs; }, get changes() { return changes; }, set now(v) { now = v; } };
}
test('subscribes only with scoped signatures, refreshes after all acknowledgements, handles real pushes and ping', async () => {
    const h = harness(); h.connection.start(); await flush();
    assert.match(h.sockets[0].url, /^wss:\/\/company.example\/app\/offline-key\?protocol=7/);
    assert.equal(h.connection.connected(), false);
    await h.establish();
    assert.equal(h.authorizations.length, 2);
    assert.equal(h.connection.connected(), true);
    assert.equal(h.changes, 1, 'catch-up after subscription');
    h.frame('support.changed', { refresh: true }, 'private-support.user.foreign');
    assert.equal(h.changes, 1);
    h.frame('support.changed', { refresh: true }, h.config.channels[0]);
    assert.equal(h.changes, 2);
    h.frame('pusher:ping', {});
    assert.equal(h.sockets[0].sent.at(-1).event, 'pusher:pong');
    h.connection.stop(); assert.equal(h.timers.size, 0);
});
test('reconnects after failure and reauthorizes the current scope on lease renewal; old socket events are ignored', async () => {
    const h = harness(); h.connection.start(); await flush(); await h.establish();
    const old = h.sockets[0];
    old.handlers.close(); assert.equal(h.connection.connected(), false);
    await h.timer(delay => delay >= 1000 && delay < 1500);
    await h.establish(); assert.equal(h.changes, 2);
    old.handlers.message(JSON.stringify({ event: 'support.changed', channel: h.config.channels[0], data: '{}' }));
    assert.equal(h.changes, 2);
    await h.timer(60000);
    assert.equal(h.configs, 3); assert.equal(h.sockets[1].closed, true);
    await h.establish(); assert.equal(h.changes, 3);
    h.connection.stop();
});
test('auth denial and a stalled heartbeat fall back without marking the socket connected', async () => {
    const denied = harness({ authFails: true }); denied.connection.start(); await flush();
    denied.frame('pusher:connection_established', { socket_id: '123.456' }); await flush();
    assert.equal(denied.connection.connected(), false); assert.equal(denied.sockets[0].closed, true); denied.connection.stop();
    const h = harness(); h.connection.start(); await flush(); await h.establish();
    h.now = 28000; await h.timer(5000);
    assert.equal(h.sockets[0].sent.at(-1).event, 'pusher:ping');
    h.now = 39000; await h.timer(5000);
    assert.equal(h.connection.connected(), false); h.connection.stop();
});
test('stop cancels pending configuration and sockets; native URL construction needs no browser global', async () => {
    const h = harness({ configPending: true }); h.connection.start(); await flush();
    h.connection.stop(); h.resolveConfig(); await flush();
    assert.equal(h.sockets.length, 0); assert.equal(h.timers.size, 0);
    assert.equal(h.exports.supportSocketUrl('http://a.localhost:8000', { path: '/app/key', port: 8080 }), 'ws://a.localhost:8080/app/key?protocol=7&client=card-support&version=1.0&flash=false');
    for (const origin of ['https://user:secret@company.example', 'javascript:foo', 'https://company.example/foreign']) assert.throws(() => h.exports.supportSocketUrl(origin, { path: '/app/key' }));
    assert.throws(() => h.exports.supportSocketUrl('https://company.example', { path: '//foreign.example/token' }));
});
