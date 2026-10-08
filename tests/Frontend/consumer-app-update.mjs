import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function setup(platform = 'app', os = 'android') {
    const exports = {};
    let response = { tenantSlug: 'tenant-a', appId: '__UNI__TEST', versionCode: 2, versionName: '1.0.1', androidDownloadUrl: 'https://download.example/app.apk', iosDistributionUrl: 'https://install.example/ios', path: '/app-releases/abcd/' + 'a'.repeat(64) + '.apk' };
    let discoveries = 0;
    let requests = 0, opened = '';
    const uni = { getSystemInfoSync: () => ({ platform: os }), getAppBaseInfo: () => ({ appId: '__UNI__TEST', appVersionCode: '1' }), request: args => { requests++; assert.ok(discoveries > 0); assert.equal(args.url, 'https://company.example/api/mobile/v1/app-release'); assert.equal(args.withCredentials, false); assert.equal(args.header.Authorization, undefined); args.success({ statusCode: 200, data: response }); } };
    const source = readFileSync('mobile/uni-app/src/lib/app-update.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText, {
        exports, uni, plus: { runtime: { openURL: url => { opened = url; } } },
        require: name => name === 'vue' ? { ref: value => ({ value }) } : name.includes('company.json') ? { tenantSlug: 'tenant-a' } : { companyOrigin: () => 'https://company.example', refreshCompanyOrigins: async () => { discoveries++; } },
    });
    return { exports, uni, response, requests: () => requests, discoveries: () => discoveries, opened: () => opened };
}
test('old apps cannot continue; update metadata uses the company API and download uses the static host', async () => {
    const c = setup();
    await assert.rejects(c.exports.ensureLatestApp());
    assert.equal(c.exports.appUpdate.value.status, 'required');
    c.exports.downloadAppUpdate();
    assert.equal(c.opened(), 'https://download.example/app.apk');
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
    for (const patch of [{tenantSlug:'other'}, {appId:'wrong'}, {androidDownloadUrl:'javascript:alert(1)'}, {versionCode:0}]) {
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

test('foreground and manual retries refresh the company directory before release checks', async () => {
    const c = setup();
    await c.exports.checkAppUpdate(true);
    await c.exports.checkAppUpdate(true);
    assert.equal(c.discoveries(), 2);
    assert.equal(c.requests(), 2);
});

for (const os of ['android', 'ios']) {
    test(`${os} uses its own destination and needs no APK path or signature`, async () => {
        const c = setup('app', os); delete c.response.path;
        await assert.rejects(c.exports.ensureLatestApp());
        assert.equal(c.exports.appUpdate.value.status, 'required');
        c.exports.downloadAppUpdate();
        assert.equal(c.opened(), os === 'android' ? c.response.androidDownloadUrl : c.response.iosDistributionUrl);
    });
    test(`${os} rejects missing and unsafe update destinations`, async () => {
        for (const url of [undefined, '', 'javascript:alert(1)', '//example.com/a', 'https://user:pass@example.com/a', 'https://example.com/has space', 'https://example.com/\\evil']) {
            const c = setup('app', os);
            c.response[os === 'android' ? 'androidDownloadUrl' : 'iosDistributionUrl'] = url;
            await assert.rejects(c.exports.ensureLatestApp());
            assert.equal(c.exports.appUpdate.value.status, 'error');
            c.exports.downloadAppUpdate(); assert.equal(c.opened(), '');
        }
    });
}
test('current iOS release passes without a distribution URL; older iOS never falls back to APK', async () => {
    const c = setup('app', 'ios'); delete c.response.iosDistributionUrl;
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '2' });
    await c.exports.ensureLatestApp();
    assert.equal(c.exports.appUpdate.value.status, 'ready');
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '1' });
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'error');
});
