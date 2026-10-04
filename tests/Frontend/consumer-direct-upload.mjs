import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function client(platform = 'app', ossStatus = 204, backupStatus = 204, serverOnly = false) {
    const calls = [];
    const uni = {
        getLocale: () => 'en', getImageInfo: o => o.success(platform === 'app' ? { type: 'png' } : {}),
        request(o) {
            calls.push(o);
            o.success({ statusCode: o.url.endsWith('/complete') ? 204 : 200, data: o.url.endsWith('/images/direct')
                ? serverOnly ? { id: 'ticket', mode: 'server' } : o.data.purpose === 'kyc' ? { id: 'ticket', mode: 'kyc_url', imageUrl: 'https://bucket.example.org/images/original', url: 'https://bucket.example.org', fields: { policy: 'short-policy', key: 'images/original' } } : { id: 'ticket', url: 'https://bucket.example.org', fields: { policy: 'short-policy', key: 'staging/key' } } : { ok: true } });
        },
        uploadFile(o) { calls.push(o); o.success({ statusCode: o.url.endsWith('/backup') ? backupStatus : ossStatus }); },
    };
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/lib/api.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText,
        { exports, uni, plus: { os: { name: 'Android' }, android: {} }, fetch: async () => ({ blob: async () => ({ type: 'image/png' }) }),
            require: id => id === './device-credentials' ? { androidCredentialVault: () => ({ read: () => null, write() {} }) } : id.includes('company.json') ? { default: { appId: 'test', tenantSlug: 'company-a', apiOrigin: 'https://app.example.org' } } : { ensureLatestApp: async () => {}, companyOrigin: () => 'https://app.example.org', ensureCompanyOrigin: async () => {} } });
    exports.setToken('private-token'); return { api: exports, calls, uni };
}
for (const platform of ['app', 'h5']) {
    test(platform + ' saves same image on server and OSS without leaking API credentials to OSS', async () => {
        const { api, calls } = client(platform);
        await api.upload('/support/messages', { request_id: 'request', support_message: 'private' }, [{ name: 'support_image', path: 'blob:image' }]);
        assert.equal(calls.length, 5);
        assert.ok(calls[1].url.endsWith('/backup'));
        assert.equal(calls[1].filePath, calls[2].filePath);
        assert.equal(calls[2].url, 'https://bucket.example.org');
        assert.deepEqual(Object.keys(calls[2].header), []);
        assert.equal(calls[2].formData.support_message, undefined);
        assert.equal(calls[4].data.support_image_upload_id, 'ticket');
        if (platform === 'app') assert.equal(calls[1].header.Authorization, 'Bearer private-token');
    });
    test(platform + ' KYC uploads straight to OSS and submits exact original URLs without backup or complete', async () => {
        const { api, calls } = client(platform);
        await api.upload('/client/kyc/applications', { identity_number: 'synthetic' }, [{ name: 'front', path: 'blob:front' }, { name: 'back', path: 'blob:back' }]);
        assert.equal(calls.length, 5);
        assert.equal(calls.filter(o => /\/(backup|complete)$/.test(o.url)).length, 0);
        assert.deepEqual(Object.keys(calls[2].header), []);
        assert.equal(calls[4].data.front_upload_id, 'ticket');
        assert.equal(calls[4].data.front_url, 'https://bucket.example.org/images/original');
        assert.equal(calls[4].data.back_url, 'https://bucket.example.org/images/original');
    });
}
test('server backup failure stops before OSS or business submission', async () => {
    const { api, calls } = client('app', 204, 500);
    await assert.rejects(api.upload('/support/messages', {}, [{ name: 'support_image', path: 'local' }]));
    assert.equal(calls.length, 2);
});
test('OSS failure retains server copy and lets server completion record repair without replaying business', async () => {
    const { api, calls } = client('app', 403);
    await api.upload('/support/messages', {}, [{ name: 'support_image', path: 'local' }]);
    assert.equal(calls.filter(o => o.url.endsWith('/support/messages')).length, 1);
    assert.ok(calls[3].url.endsWith('/complete'));
});

for (const platform of ['app', 'h5']) {
    test(platform + ' server-only ticket uploads exclusively to the application and submits once', async () => {
        const { api, calls } = client(platform, 204, 204, true);
        await api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]);
        assert.equal(calls.length, 4);
        assert.ok(calls[1].url.endsWith('/backup'));
        assert.ok(calls[2].url.endsWith('/complete'));
        assert.equal(calls[3].data.front_upload_id, 'ticket');
        assert.ok(calls.every(c => !c.url.includes('bucket.example.org')));
    });
}

for (const platform of ['app', 'h5']) {
    test(platform + ' failed KYC OSS upload prevents OCR submission and never falls back locally', async () => {
        const { api, calls } = client(platform, 403);
        await assert.rejects(api.upload('/client/kyc/applications', {}, [{ name: 'front', path: 'blob:front' }]));
        assert.equal(calls.length, 2);
        assert.equal(calls[1].url, 'https://bucket.example.org');
    });
}

for (const platform of ['app', 'h5']) {
    for (const fail of [false, true]) test(platform + ' parallel KYC waits for both uploads and preserves fields, failure=' + fail, async () => {
        const { api, calls, uni } = client(platform);
        const pending = [], progress = [];
        const original = uni.request;
        uni.request = o => {
            if (o.url.endsWith('/images/direct')) {
                calls.push(o);
                const side = o.data.field;
                o.success({ statusCode: 200, data: { id: side, mode: 'kyc_url', url: 'https://bucket.example.org', imageUrl: 'https://bucket.example.org/' + side, fields: { key: side } } });
            } else original(o);
        };
        uni.uploadFile = o => { calls.push(o); pending.push(o); };
        let settled = false;
        const promise = api.upload('/client/kyc/applications', { reverify: true }, [{ name: 'front', path: 'blob:front' }, { name: 'back', path: 'blob:back' }], p => progress.push(p));
        const result = promise.then(v => { settled = true; return v; }, e => { settled = true; return e; });
        while (pending.length < 2) await new Promise(resolve => setImmediate(resolve));
        assert.equal(pending.length, 2);
        assert.equal(pending[0].formData.key, 'front');
        assert.equal(pending[1].formData.key, 'back');
        pending[1].success({ statusCode: fail ? 403 : 204 });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(settled, false);
        pending[0].success({ statusCode: 204 });
        await result;
        const submissions = calls.filter(c => c.url.endsWith('/client/kyc/applications'));
        assert.equal(submissions.length, fail ? 0 : 1);
        if (!fail) {
            assert.equal(submissions[0].data.reverify, true);
            assert.equal(submissions[0].data.front_url, 'https://bucket.example.org/front');
            assert.equal(submissions[0].data.back_upload_id, 'back');
            assert.equal(progress.at(-1).stage, 'submitting');
        }
        const count = progress.length;
        pending[0].success({ statusCode: 204 });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(progress.length, count);
    });
}
