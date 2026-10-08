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
    const runtime = { openURL: (url, fail) => { opened = url; } };
    const uni = { getSystemInfoSync: () => ({ platform: os }), getAppBaseInfo: () => ({ appId: '__UNI__TEST', appVersionCode: '1' }), request: args => { requests++; assert.ok(discoveries > 0); assert.equal(args.url, 'https://company.example/api/mobile/v1/app-release'); assert.equal(args.withCredentials, false); assert.equal(args.header.Authorization, undefined); args.success({ statusCode: 200, data: response }); } };
    const source = readFileSync('mobile/uni-app/src/lib/app-update.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText, {
        exports, uni, plus: { runtime },
        require: name => name === 'vue' ? { ref: value => ({ value }) } : name.includes('company.json') ? { tenantSlug: 'tenant-a' } : { companyOrigin: () => 'https://company.example', refreshCompanyOrigins: async () => { discoveries++; } },
    });
    return { exports, uni, response, runtime, requests: () => requests, discoveries: () => discoveries, opened: () => opened };
}
test('old apps offer an optional platform download from the company API', async () => {
    const c = setup();
    await c.exports.checkAppUpdate();
    assert.equal(c.exports.appUpdate.value.status, 'available');
    c.exports.downloadAppUpdate();
    assert.equal(c.opened(), 'https://download.example/app.apk');
});
test('current and newer apps pass and concurrent checks share one request', async () => {
    const c = setup(); c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '2' });
    await Promise.all([c.exports.checkAppUpdate(), c.exports.checkAppUpdate()]);
    assert.equal(c.requests(), 1);
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '3' });
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'ready');
});
test('unsafe URL and invalid versions suppress the optional update and can retry', async () => {
    for (const patch of [{androidDownloadUrl:'javascript:alert(1)'}, {versionCode:0}]) {
        const c = setup(); Object.assign(c.response, patch);
        await c.exports.checkAppUpdate();
        assert.equal(c.exports.appUpdate.value.status, 'error');
        c.exports.downloadAppUpdate(); assert.equal(c.opened(), '');
    }
    const c = setup(); c.uni.request = args => args.fail();
    await c.exports.checkAppUpdate();
    assert.equal(c.exports.appUpdate.value.status, 'error');
    c.uni.request = args => args.success({statusCode:200,data:c.response});
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'available');
});
test('H5 does not perform native release checks', async () => {
    const c = setup('h5'); await c.exports.checkAppUpdate();
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
        await c.exports.checkAppUpdate();
        assert.equal(c.exports.appUpdate.value.status, 'available');
        c.exports.downloadAppUpdate();
        assert.equal(c.opened(), os === 'android' ? c.response.androidDownloadUrl : c.response.iosDistributionUrl);
    });
    test(`${os} rejects missing and unsafe update destinations`, async () => {
        for (const url of [undefined, '', 'javascript:alert(1)', '//example.com/a', 'https://user:pass@example.com/a', 'https://example.com/has space', 'https://example.com/\\evil']) {
            const c = setup('app', os);
            c.response[os === 'android' ? 'androidDownloadUrl' : 'iosDistributionUrl'] = url;
            await c.exports.checkAppUpdate();
            assert.equal(c.exports.appUpdate.value.status, 'error');
            c.exports.downloadAppUpdate(); assert.equal(c.opened(), '');
        }
    });
}
test('current iOS release passes without a distribution URL; older iOS never falls back to APK', async () => {
    const c = setup('app', 'ios'); delete c.response.iosDistributionUrl;
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '2' });
    await c.exports.checkAppUpdate();
    assert.equal(c.exports.appUpdate.value.status, 'ready');
    c.uni.getAppBaseInfo = () => ({ appId: '__UNI__TEST', appVersionCode: '1' });
    await c.exports.checkAppUpdate(true);
    assert.equal(c.exports.appUpdate.value.status, 'error');
});

for (const os of ['android', 'ios']) {
    test(`${os} accepts common releases independently of company and DCloud AppID`, async () => {
        const c = setup('app', os);
        c.response.tenantSlug = 'another-company';
        c.response.appId = '__UNI__OTHER';
        await c.exports.checkAppUpdate();
        assert.equal(c.exports.appUpdate.value.status, 'available');
        c.exports.dismissAppUpdate();
        await c.exports.checkAppUpdate(true);
        assert.equal(c.exports.appUpdate.value.status, 'ready');
        c.response.versionCode = 3;
        await c.exports.checkAppUpdate(true);
        assert.equal(c.exports.appUpdate.value.status, 'available');
    });
    test(`${os} download closes the notice even when opening fails`, async () => {
        const c = setup('app', os);
        await c.exports.checkAppUpdate();
        c.runtime.openURL = (_url, fail) => fail();
        c.exports.downloadAppUpdate();
        assert.equal(c.exports.appUpdate.value.status, 'ready');
        await c.exports.checkAppUpdate(true);
        assert.equal(c.exports.appUpdate.value.status, 'ready');
    });
}
test('business requests and startup work never await the optional version check', () => {
    const api = readFileSync('mobile/uni-app/src/lib/api.ts', 'utf8');
    assert.doesNotMatch(api, /ensureLatestApp|checkAppUpdate|ApiError\(426\)/);
    const app = readFileSync('mobile/uni-app/src/App.vue', 'utf8');
    assert.match(app, /void checkAppUpdate\(true\);/);
    assert.doesNotMatch(app, /checkAppUpdate\(true\)\s*\.then/);
    const notice = readFileSync('mobile/uni-app/src/components/AppUpdateGate.vue', 'utf8');
    assert.match(notice, /v-if="appUpdate.status === 'available'"/);
    assert.match(notice, /@click="dismissAppUpdate"/);
});
