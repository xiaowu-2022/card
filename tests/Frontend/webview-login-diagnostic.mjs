import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const exports = {};
runInNewContext(ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/login-diagnostic.ts', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, { exports });
const diagnostic = exports.loginDiagnostic;
test('each actionable capture failure has a distinct safe diagnostic code', () => {
    const cases = [
        ['claim', { status: 410 }, 'CLAIM-HTTP-410'],
        ['claim', { status: 422 }, 'CLAIM-HTTP-422'],
        ['claim', { status: 429 }, 'CLAIM-HTTP-429'],
        ['claim', { diagnostic: 'timeout' }, 'CLAIM-TIMEOUT'],
        ['claim', { diagnostic: 'network' }, 'CLAIM-NETWORK'],
        ['verify', { status: 401 }, 'VERIFY-HTTP-401'],
        ['verify', { diagnostic: 'identity' }, 'VERIFY-IDENTITY'],
        ['storage', Error('Protected credential save failed'), 'IOS-WRITE'],
        ['storage', Error('Protected credential save unavailable'), 'IOS-PROTECTION'],
        ['storage', Error('Protected credential backup exclusion failed'), 'IOS-BACKUP'],
        ['storage', Error('Protected credential storage locked'), 'IOS-LOCKED'],
    ];
    for (const [stage, error, code] of cases) assert.equal(diagnostic(stage, error), code);
    assert.equal(new Set(cases.map(([, , code]) => code)).size, cases.length);
});
test('unexpected errors and server content cannot leak into diagnostics', () => {
    for (const error of [null, undefined, Error('TOKEN|SECRET account-name'), { status: 'SECRET', diagnostic: 'SECRET' }, { status: 999, message: 'SECRET' }]) {
        assert.equal(diagnostic('storage', error), 'SAVE-UNEXPECTED');
    }
});

test('native storage exceptions carry only a fixed operation name',()=>{
 assert.equal(exports.loginDiagnostic('storage',Object.assign(Error('Protected credential native bridge failed'),{iosStep:'ENCODE'})),'IOS-NATIVE-ENCODE');
 assert.equal(exports.loginDiagnostic('storage',Object.assign(Error('Protected credential native bridge failed'),{iosStep:'secret'})),'IOS-NATIVE');
 assert.equal(exports.loginDiagnostic('storage',Error('Protected credential record decode failed')),'IOS-JSON');
});

test('detailed codes cannot carry arbitrary native content',()=>{
 for(const code of ['IOS-READBACK-MISMATCH','SAVE-META-COMMIT','IOS-PATH-INVALID']) assert.equal(diagnostic('storage',{storageCode:code}),code);
 assert.equal(diagnostic('storage',{storageCode:'TOKEN|SECRET'}),'SAVE-UNEXPECTED');
});
