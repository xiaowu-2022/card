import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function setup(platform = 'app') {
    const exports = {};
    let response = { tenantSlug: 'tenant-a', appId: '__UNI__TEST', versionCode: 2, versionName: '1.0.1', path: '/app-releases/abcd/' + 'a'.repeat(64) + '.apk' };
    let requests = 0, opened = '';
    const uni = { getAppBaseInfo: () => ({ appId: '__UNI__TEST', appVersionCode: '1' }), request: args => { requests++; assert.equal(args.withCredentials, false); assert.equal(args.header.Authorization, undefined); args.success({ statusCode: 200, data: response }); } };
    const source = readFileSync('mobile/uni-app/src/lib/app-update.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText, {
        exports, uni, plus: { runtime: { openURL: url => { opened = url; } } },
        require: name => name === 'vue' ? { ref: value => ({ value }) } : name.includes('company.json') ? { tenantSlug: 'tenant-a' } : { companyOrigin: () => 'https://company.example', ensureCompanyOrigin: async () => {} },
    });
    return { exports, uni, response, requests: () => requests, opened: () => opened };
}
test('old apps cannot continue; update opens only the validated company APK', async () => {
    const c = setup();
    await assert.rejects(c.exports.ensureLatestApp());
    assert.equal(c.exports.appUpdate.value.status, 'required');
    c.exports.downloadAppUpdate();
    assert.equal(c.opened(), 'https://company.example' + c.response.path);
});
test('current and newer apps pass and concurrent checks share one request', async () => {
    const c = setup(); c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '2' });
    await Promise.all([c.exports.ensureLatestApp(), c.exports.ensureLatestApp()]);
    assert.equal(c.requests(), 1);
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '3' });
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'ready');
});
test('wrong company, app, URL and invalid versions fail closed and can retry', async () => {
    for (const patch of [{tenantSlug:'other'}, {appId:'wrong'}, {path:'https://evil.example/a.apk'}, {versionCode:0}]) {
        const c = setup(); Object.assign(c.response, patch);
        await assert.rejects(c.exports.ensureLatestApp());
        assert.equal(c.exports.appUpdate.value.status, 'error');
        c.exports.downloadAppUpdate(); assert.equal(c.opened(), '');
    }
    const c = setup(); c.uni.request = args => args.fail();
    await assert.rejects(c.exports.ensureLatestApp());
    assert.equal(c.exports.appUpdate.value.status, 'error');
    c.uni.request = args => args.success({statusCode:200,data:c.response});
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'required');
});
test('H5 does not perform native release checks', async () => {
    const c = setup('h5'); await c.exports.ensureLatestApp();
    assert.equal(c.requests(), 0); assert.equal(c.exports.appUpdate.value.status, 'ready');
});
