// Offline protocol check: synthetic keys, local isolated Reverb, no customer messages or database writes.
import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
const require = createRequire(import.meta.url);
const WebSocket = require('playwright-core/lib/utilsBundle').ws;
const docker = process.env.DOCKER_BIN ?? '/usr/local/bin/docker';
const name = 'card-support-reverb-offline-' + process.pid;
const port = 18091;
const key = 'offline-support-key', secret = 'offline-support-secret', appId = 'offline-support-app';
const endpoint = `http://127.0.0.1:${port}`;
const exports = {};
vm.runInNewContext(ts.transpileModule(readFileSync('shared/support-realtime.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, {
    exports, Date, Math, setTimeout, clearTimeout, setInterval, clearInterval,
});
const connections = [];
function rawSocket() {
    const socket = new WebSocket(endpoint.replace('http:', 'ws:') + '/app/' + key + '?protocol=7&client=offline-test&version=1.0', { origin: 'http://localhost' });
    const frames = [];
    socket.on('message', bytes => frames.push(JSON.parse(bytes.toString())));
    connections.push({ stop: () => socket.close() });
    return { socket, frames };
}
async function until(predicate, label) {
    const end = Date.now() + 5000;
    while (!predicate() && Date.now() < end) await delay(20);
    assert.ok(predicate(), label);
}
async function publish(channel) {
    const body = JSON.stringify({ name: 'support.changed', channels: [channel], data: JSON.stringify({ refresh: true }) });
    const query = new URLSearchParams({ auth_key: key, auth_timestamp: String(Math.floor(Date.now() / 1000)), auth_version: '1.0', body_md5: createHash('md5').update(body).digest('hex') });
    const path = '/apps/' + appId + '/events';
    query.set('auth_signature', createHmac('sha256', secret).update('POST\n' + path + '\n' + query.toString()).digest('hex'));
    const response = await fetch(endpoint + path + '?' + query, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
    assert.equal(response.status, 200);
}
try {
    execFileSync(docker, ['run', '--rm', '-d', '--name', name, '--network', 'card_browser', '-p', `127.0.0.1:${port}:8080`,
        '-v', resolve('.') + ':/var/www/html', '-w', '/var/www/html',
        '-e', 'APP_ENV=testing', '-e', 'DB_DATABASE=card_ui_test', '-e', 'REVERB_APP_ID=' + appId,
        '-e', 'REVERB_APP_KEY=' + key, '-e', 'REVERB_APP_SECRET=' + secret, '-e', 'REVERB_ALLOWED_ORIGINS=localhost',
        '--entrypoint', 'php', 'card-app', 'artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080'], { stdio: 'pipe' });
    let ready = false;
    for (let i = 0; i < 50; i++) {
        try { await fetch(endpoint); ready = true; break; } catch { await delay(100); }
    }
    assert.ok(ready, 'local isolated server started');
    let changes = 0;
    const channel = 'private-support.user.abc';
    const client = exports.createSupportRealtime({
        origin: () => 'http://127.0.0.1',
        configuration: async () => ({ enabled: true, path: '/app/' + key, port, channels: [channel] }),
        // Synthetic signature only: real HTTP authorization is covered by isolated feature tests.
        authorize: async (id, name) => ({ auth: key + ':' + createHmac('sha256', secret).update(id + ':' + name).digest('hex') }),
        connect(url, handlers) {
            const socket = new WebSocket(url, { origin: 'http://localhost' });
            socket.on('message', bytes => handlers.message(bytes.toString()));
            socket.on('close', handlers.close);
            socket.on('error', () => socket.close());
            return { send: data => socket.send(data), close: () => socket.close() };
        },
        changed: () => changes++, status: () => {},
    });
    connections.push(client); client.start();
    await until(() => client.connected(), 'private subscription acknowledged');
    assert.equal(changes, 1, 'shared client caught up on subscription');
    const started = Date.now();
    await publish(channel);
    await until(() => changes === 2, 'published notification received');
    const latency = Date.now() - started;
    await publish('private-support.user.foreign');
    await delay(100); assert.equal(changes, 2, 'foreign private channel did not deliver');
    const denied = rawSocket();
    await until(() => denied.frames.some(f => f.event === 'pusher:connection_established'), 'raw socket established');
    denied.socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: key + ':wrong-signature' } }));
    await until(() => denied.frames.some(f => f.event === 'pusher:error'), 'invalid signature rejected');
    assert.equal(denied.frames.some(f => f.event === 'pusher_internal:subscription_succeeded'), false);
    const forged = rawSocket();
    await until(() => forged.frames.some(f => f.event === 'pusher:connection_established'), 'second raw socket established');
    forged.socket.send(JSON.stringify({ event: 'client-support.changed', channel, data: { refresh: true } }));
    await until(() => forged.frames.some(f => f.event === 'pusher:error'), 'client publication rejected');
    assert.equal(changes, 2);
    console.log(`Offline Reverb check passed: scoped push ${latency}ms, catch-up, channel isolation, signature rejection, client-event rejection.`);
} finally {
    for (const connection of connections) connection.stop();
    try { execFileSync(docker, ['stop', '-t', '2', name], { stdio: 'pipe' }); } catch { /* Already stopped. */ }
}
