import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function client(platform = 'app', uploadStatus = 204) {
    const calls = [];
    const uni = {
        getLocale: () => 'en',
        getImageInfo: o => o.success(platform === 'app' ? { type: 'png' } : {}),
        request(o) {
            calls.push(o);
            o.success({ statusCode: o.url.endsWith('/complete') ? 204 : 200, data: o.url.endsWith('/images/direct')
                ? { id: 'ticket', url: 'https://bucket.example.org', imageUrl: 'https://bucket.example.org/images/test', fields: { policy: 'short-policy', key: 'staging/key' } }
                : { ok: true } });
        },
        uploadFile(o) { calls.push(o); o.success({ statusCode: uploadStatus }); },
    };
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/lib/api.ts', 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform));
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText,
        { exports, uni, fetch: async () => ({ blob: async () => ({ type: 'image/png' }) }),
            require: id => id.includes('company.json') ? {} : { companyOrigin: () => 'https://app.example.org', ensureCompanyOrigin: async () => {} } });
    exports.setToken('private-token');
    return { api: exports, calls };
}
for (const platform of ['app', 'h5']) {
    test(platform + ' sends bytes only to OSS and submits authenticated image references', async () => {
        const { api, calls } = client(platform);
        await api.upload('/support/messages', { request_id: 'request', support_message: 'private message' }, [{ name: 'support_image', path: 'blob:local-image' }]);
        assert.equal(calls.length, 4);
        assert.equal(calls[1].url, 'https://bucket.example.org');
        assert.deepEqual(Object.keys(calls[1].header), []);
        assert.equal(calls[1].formData.support_message, undefined);
        assert.equal(calls[3].filePath, undefined);
        assert.equal(calls[3].data.support_image_upload_id, 'ticket');
        assert.equal(calls[3].data.support_message, 'private message');
        if (platform === 'app') assert.equal(calls[3].header.Authorization, 'Bearer private-token');
    });
}
test('failed OSS uploads never call completion or submit business mutations', async () => {
    const { api, calls } = client('app', 403);
    await assert.rejects(api.upload('/support/messages', { request_id: 'request' }, [{ name: 'support_image', path: 'local-image' }]));
    assert.equal(calls.length, 2);
});

for (const platform of ['app', 'h5']) {
    test(platform + ' submits uploaded KYC URLs directly without a completion request', async () => {
        const { api, calls } = client(platform);
        await api.upload('/client/kyc/applications', { identity_number: 'synthetic' }, [
            { name: 'front', path: 'blob:front' }, { name: 'back', path: 'blob:back' },
        ]);
        assert.equal(calls.length, 5);
        assert.equal(calls.some(o => o.url.endsWith('/complete')), false);
        assert.equal(calls[4].data.front_url, 'https://bucket.example.org/images/test');
        assert.equal(calls[4].data.back_url, 'https://bucket.example.org/images/test');
        assert.equal(calls[1].formData.identity_number, undefined);
        assert.equal(calls[3].formData.identity_number, undefined);
        assert.equal(calls[4].data.front_upload_id, 'ticket');
    });
}
