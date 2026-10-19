import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const exports = {};
runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/ios-credentials.ts', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, { exports });
const token = '12|' + 'A'.repeat(64), scope = 'webview-token:app:company:id';
function native() {
    const state = { file: null, protection: 'NSFileProtectionComplete', locked: false, backup: true, writes: [], released: [] };
    const ios = { deleteObject: object => state.released.push(object), invoke(object, method, ...args) {
        if (object === 'NSFileManager') return { kind: 'manager' };
        if (method === 'fileExistsAtPath:') return state.file !== null;
        if (method === 'attributesOfItemAtPath:error:') return { kind: 'attributes' };
        if (method === 'objectForKey:') return state.protection;
        if (method === 'removeItemAtPath:error:') { state.file = null; return true; }
        if (method === 'stringWithContentsOfFile:encoding:error:') return state.locked ? null : state.file;
        if (object === 'NSMutableDictionary') return { kind: 'dictionary', value: {} };
        if (method === 'setObject:forKey:') { object.value[args[1]] = args[0]; return; }
        if (object === 'NSJSONSerialization') return { kind: 'data', value: JSON.stringify(args[0].value) };
        if (method === 'writeToFile:options:error:') {
            state.writes.push({ path: args[0], flags: args[1] });
            if (state.locked) return false;
            state.file = object.value; return true;
        }
        if (object === 'NSURL') return { kind: 'url' };
        if (method === 'setResourceValue:forKey:error:') {
            assert.equal(args[0], true); assert.equal(args[1], 'NSURLIsExcludedFromBackupKey');
            return state.backup;
        }
        throw Error('Unexpected native method ' + method);
    } };
    return { state, vault: exports.iosCredentialVault(ios, '/private/app/consumer-token.json', scope) };
}
test('iOS requires atomic complete Data Protection and backup exclusion, then checks scope on read', () => {
    const { state, vault } = native();
    assert.equal(vault.read(), null);
    vault.write(token);
    assert.equal(state.writes[0].flags, 0x20000001);
    assert.equal(state.released.length, 3);
    assert.equal(vault.read(), token);
    state.file = JSON.stringify({ scope: 'other-company', token });
    assert.throws(() => vault.read(), /Invalid/);
    vault.write(null); assert.equal(state.file, null);
});
test('locked storage and missing protection never become an anonymous fallback or a successful save', () => {
    const { state, vault } = native();
    vault.write(token); state.locked = true;
    assert.throws(() => vault.read(), /locked/);
    assert.throws(() => vault.write(token), /save failed/);
    state.locked = false; state.protection = 'NSFileProtectionNone';
    assert.throws(() => vault.read(), /unavailable/);
    assert.throws(() => vault.write(token), /unavailable/);
    assert.equal(state.file, null);
});
test('failed backup exclusion removes the credential and fails closed', () => {
    const { state, vault } = native(); state.backup = false;
    assert.throws(() => vault.write(token), /backup exclusion/);
    assert.equal(state.file, null);
});
