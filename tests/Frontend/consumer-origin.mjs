import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function client(platform, base = '/', initialAssets = {}) {
    const cache = new Map();
    const calls = [];
    const uni = { getSystemInfoSync: () => ({ platform: 'android' }), getAppBaseInfo: () => ({ appId: '__UNI__TEST', appVersionCode: '2' }), getLocale: () => 'en', getStorageSync: () => [], setStorageSync() {},
        request(options) {
            calls.push(options);
            if (options.url.endsWith('/domains')) options.success({ statusCode: 200, data: { tenant: { id: 'tenant-a', slug: 'company-a' }, origins: ['https://primary.example.org'] } });
            else if (options.url.endsWith('/app-release')) options.success({ statusCode: 200, data: { tenantSlug: 'company-a', appId: '__UNI__TEST', versionCode: 2, versionName: '1.0.1', path: '/app-releases/abcd/' + 'a'.repeat(64) + '.apk' } });
            else options.fail();
        },
        uploadFile(options) { calls.push(options); options.fail(); },
        downloadFile(options) { calls.push(options); options.fail(); },
    };
    const window = { __PUBLIC_ASSETS__: initialAssets, location: { origin: 'https://alternate.example.org', href: 'https://alternate.example.org/' } };
    function load(path) {
        if (path.endsWith('device-credentials.ts')) return { androidCredentialVault: () => ({ read: () => null, write() {} }) };
        if (path === 'vue') return { ref: (value) => ({ value }), shallowRef: (value) => ({ value }) };
        if (path.endsWith('company.json')) return { apiOrigin: 'https://primary.example.org', apiOrigins: ['https://zb33333.com', 'https://primary.example.org'], tenantSlug: 'company-a', appId: 'test.cards.app', developmentOnly: false };
        if (cache.has(path)) return cache.get(path);
        const exports = {};
        cache.set(path, exports);
        const source = readFileSync(path, 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform)).replaceAll('import.meta.env.BASE_URL', JSON.stringify(base));
        const compiled = ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
        }).outputText;
        runInNewContext(compiled, { exports, window, URL, uni, plus: { os: { name: 'Android' }, android: {} }, require: (id) => id === 'vue' ? load('vue') : load(resolve(dirname(path), id + (id.endsWith('.json') ? '' : '.ts'))) });
        return exports;
    }
    const sourceDir = resolve('mobile/uni-app/src/lib');
    return { window, calls, uni, origin: load(resolve(sourceDir, 'origin.ts')), api: load(resolve(sourceDir, 'api.ts')), navigation: load(resolve(sourceDir, 'navigation.ts')) };
}

test('H5 follows the current domain for links, image paths and internal navigation', () => {
    const c = client('h5');
    for (const domain of ['https://alternate.example.org', 'https://second.example.org']) {
        c.window.location.origin = domain;
        assert.equal(c.origin.companyOrigin(), domain);
        assert.equal(c.api.photoUrl(domain + '/storage/image.png'), '/storage/image.png');
        assert.equal(c.navigation.internalUrl(domain + '/login'), '/pages/login/index');
        assert.throws(() => c.navigation.internalUrl('https://primary.example.org/login'));
        assert.equal(c.api.photoUrl('https://images.example.org/image.png?x-oss-process=image%2Fresize'), 'https://images.example.org/image.png?x-oss-process=image%2Fresize');
        assert.equal(c.api.photoUrl('https://user:password@images.example.org/image.png'), '');
        assert.equal(c.api.photoUrl('javascript:alert(1)'), '');
    }
});

test('subdirectory H5 assets and invitation links remain inside the deployed H5', async () => {
    const c = client('h5', '/h5/');
    assert.equal(c.origin.staticAsset('icons/Bell.svg'), c.origin.webBase() + 'static/icons/Bell.svg');
    const invite = new URL(c.origin.invitationUrl('test+code'));
    assert.equal(invite.origin, c.window.location.origin);
    assert.equal(invite.pathname, '/h5/');
    assert.equal(new URLSearchParams(invite.hash.split('?')[1]).get('path'), '/register?invite=test%2Bcode');
    const native = client('app', '/h5/');
    await native.origin.ensureCompanyOrigin();
    assert.equal(native.origin.staticAsset('icons/Bell.svg'), '/static/icons/Bell.svg');
    assert.equal(native.origin.invitationUrl('test'), 'https://primary.example.org/register?invite=test');
});

test('native App verifies its company domain before using it even when a browser-like global exists', async () => {
    const c = client('app');
    assert.throws(() => c.origin.companyOrigin());
    await c.origin.ensureCompanyOrigin();
    assert.equal(c.origin.companyOrigin(), 'https://primary.example.org');
    assert.equal(c.api.photoUrl('/storage/image.png'), 'https://primary.example.org/storage/image.png');
    assert.equal(c.navigation.internalUrl('https://primary.example.org/login'), '/pages/login/index');
    assert.throws(() => c.navigation.internalUrl('https://alternate.example.org/login'));
});

test('one relative build follows root, renamed directories and explicit index entry points', () => {
    const c = client('h5', './');
    for (const [entry, base] of [['/', '/'], ['/client/', '/client/'], ['/another/nested/index.html', '/another/nested/']]) {
        c.window.location.href = c.window.location.origin + entry + '#/pages/login/index';
        assert.equal(c.origin.webBase(), base);
        assert.equal(c.origin.staticAsset('icons/Bell.svg'), c.origin.webBase() + 'static/icons/Bell.svg');
        assert.equal(new URL(c.origin.invitationUrl('test')).pathname, base);
    }
});


test('native requests wait for credential-free discovery and failed mutations are never replayed', async () => {
    const c = client('app');
    c.api.setToken('secret');
    const requests = [c.api.request('/login', 'POST', { password: 'secret' }), c.api.upload('/client/kyc/applications', {}, []), c.api.privateImage('/client/promotion/poster-background')];
    await Promise.all(requests.map((request) => assert.rejects(request)));
    assert.equal(c.calls.filter((call) => call.url.endsWith('/domains')).length, 1);
    assert.equal(c.calls[0].header.Authorization, undefined);
    assert.equal(c.calls[0].header['X-Consumer-Flow'], undefined);
    assert.equal(c.calls.length, 5);
    assert.ok(c.calls[1].url.endsWith('/app-release'));
    assert.equal(c.calls[1].header.Authorization, undefined);
    assert.equal(c.calls[1].header['X-Consumer-Flow'], undefined);
    assert.ok(c.calls.slice(2).every((call) => call.header.Authorization === 'Bearer secret'));
});


test('uses synchronized OSS artwork and icons while retaining startup resources', () => {
    const c = client('h5');
    c.origin.setPublicAssets({ '/images/example.png': 'https://images.example.org/assets/hash/example.png?x-oss-process=image%2Fresize', '/icons/bell.svg': 'https://images.example.org/assets/hash/bell.svg' });
    assert.equal(c.origin.staticAsset('images/example.png'), 'https://images.example.org/assets/hash/example.png?x-oss-process=image%2Fresize');
    assert.equal(c.origin.staticAsset('icons/bell.svg'), 'https://images.example.org/assets/hash/bell.svg');
    assert.equal(c.origin.staticAsset('icons/startup.svg'), '/static/icons/startup.svg');
});


test('server bootstrap clears the previously published OSS map', () => {
    const url = 'https://images.example.org/assets/hash/spec-pay-gold-world.png';
    const c = client('h5', './', { '/images/marketing/spec-pay-gold-world.png': url });
    for (const manifest of [[], {}, undefined, null, { '/images/marketing/spec-pay-gold-world.png': '/static/local.png' }, { '/images/marketing/spec-pay-gold-world.png': 'https://user:secret@images.example.org/a.png' }]) {
        c.origin.setPublicAssets(manifest);
        assert.equal(c.origin.staticAsset('images/marketing/spec-pay-gold-world.png'), '/static/images/marketing/spec-pay-gold-world.png');
        assert.equal(c.origin.staticAsset('images/missing.png'), '/static/images/missing.png');
    }
    const next = 'https://images.example.org/assets/new/spec-pay-gold-world.png';
    c.origin.setPublicAssets({ '/images/marketing/spec-pay-gold-world.png': next });
    assert.equal(c.origin.staticAsset('images/marketing/spec-pay-gold-world.png'), next);
});

 test('failed OSS artwork switches to the local H5 copy without a retry loop', () => {
    const c = client('h5', '/h5/');
    let reject; let probes = 0;
    c.uni.getImageInfo = options => { probes++; reject = options.fail; };
    c.origin.setPublicAssets({ '/images/example.png': 'https://images.example.org/assets/hash/example.png' });
    assert.equal(c.origin.staticAsset('images/example.png'), 'https://images.example.org/assets/hash/example.png');
    reject();
    assert.equal(c.origin.staticAsset('images/example.png'), '/h5/static/images/example.png');
    assert.equal(c.origin.staticAsset('images/example.png'), '/h5/static/images/example.png');
    assert.equal(probes, 1);
});

test('native discovery never probes the static APK host even from old packaged seeds', async () => {
    const c = client('app');
    await c.origin.refreshCompanyOrigins();
    assert.equal(c.origin.companyOrigin(), 'https://primary.example.org');
    assert.equal(c.calls.some(call => call.url.startsWith('https://zb33333.com/')), false);
});
