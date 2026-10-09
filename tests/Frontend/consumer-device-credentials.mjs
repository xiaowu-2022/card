import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import ts from 'typescript';
const exports = {};
runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/device-credentials.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText, { exports });
// Native bridge double backed by real AES-GCM; device bridge acceptance is separate.
function harness() {
    const keys = new Map(); let stored = null;
    const android = {
        newObject(name, ...args) {
            if (name === 'java.lang.String') return { getBytes: () => Buffer.from(args[0]), toString: () => Buffer.isBuffer(args[0]) ? args[0].toString() : args[0] };
            if (name.endsWith('$Builder')) { const b = { setBlockModes: () => b, setEncryptionPaddings: () => b, setKeySize: () => b, build: () => ({ alias: args[0] }) }; return b; }
            if (name.endsWith('GCMParameterSpec')) return { iv: args[1] };
            throw new Error(name);
        },
        invoke(object, method, ...args) {
            if (typeof object !== 'string') return object[method](...args);
            if (object === 'android.util.Base64') return method === 'decode' ? Buffer.from(args[0], 'base64') : args[0].toString('base64');
            if (object === 'java.security.KeyStore') return { load() {}, containsAlias: k => keys.has(k), getKey: k => keys.get(k) };
            if (object === 'javax.crypto.KeyGenerator') { let alias; return { init: p => { alias = p.alias; }, generateKey: () => { const key = randomBytes(32); keys.set(alias, key); return key; } }; }
            if (object === 'javax.crypto.Cipher') {
                let mode, key, iv, aad;
                return {
                    init(m, k, p) { mode = m; key = k; iv = p?.iv ?? randomBytes(12); },
                    updateAAD(a) { aad = a; }, getIV: () => iv,
                    doFinal(data) {
                        if (mode === 1) { const c = createCipheriv('aes-256-gcm', key, iv); c.setAAD(aad); return Buffer.concat([c.update(data), c.final(), c.getAuthTag()]); }
                        const c = createDecipheriv('aes-256-gcm', key, iv); c.setAAD(aad); c.setAuthTag(data.subarray(-16)); return Buffer.concat([c.update(data.subarray(0, -16)), c.final()]);
                    },
                };
            }
            throw new Error(object);
        },
    };
    const storage = { get: () => stored, set: v => { stored = v; }, remove: () => { stored = null; } };
    return { android, storage, keys, vault: (scope, valid) => exports.androidCredentialVault(android, scope, storage, valid) };
}
const secret = '42|' + 'A'.repeat(64);
test('cold restart restores encrypted credential and explicit logout removes it', () => {
    const h = harness(); h.vault('company-a').write(secret);
    assert.ok(!h.storage.get().includes(secret));
    assert.equal(h.vault('company-a').read(), secret);
    h.vault('company-a').write(null);
    assert.equal(h.vault('company-a').read(), null);
});
test('modified ciphertext, wrong company or lost Keystore key cannot restore login', () => {
    for (const damage of ['ciphertext', 'company', 'key']) {
        const h = harness(); h.vault('company-a').write(secret);
        if (damage === 'ciphertext') { const r = JSON.parse(h.storage.get()); const b = Buffer.from(r.ciphertext, 'base64'); b[0] ^= 1; r.ciphertext = b.toString('base64'); h.storage.set(JSON.stringify(r)); }
        if (damage === 'key') h.keys.clear();
        assert.equal(h.vault(damage === 'company' ? 'company-b' : 'company-a').read(), null);
        assert.equal(h.storage.get(), null);
    }
});
test('unavailable native API cannot silently fall back to plaintext persistence', () => {
    const h = harness(); h.android.invoke = () => undefined;
    assert.throws(() => h.vault('company-a').write(secret), /unavailable/);
    assert.equal(h.storage.get(), null);
});
test('WebView cookie encryption uses an explicit validator and remains bound to its company', () => {
    const h = harness(), value = 'eyJ' + 'A'.repeat(128) + '%3D';
    const valid = text => /^[A-Za-z0-9%+/=_-]{32,8192}$/.test(text);
    h.vault('webview:company-a', valid).write(value);
    assert.ok(!h.storage.get().includes(value));
    assert.equal(h.vault('webview:company-a', valid).read(), value);
    assert.equal(h.vault('webview:company-b', valid).read(), null);
    assert.throws(() => h.vault('webview:company-a', valid).write(value + '; Domain=foreign.test'), /Invalid/);
    assert.equal(h.storage.get(), null);
});
